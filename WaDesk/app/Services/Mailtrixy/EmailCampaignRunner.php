<?php

namespace App\Services\Mailtrixy;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WaTemplate;
use App\Models\WorkspaceEmailAccount;
use App\Models\Workspace;
use App\Models\WpCampaign;
use App\Models\WpCampaignContact;
use App\Services\TemplateOverrideResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sends an email campaign from PHP — the email twin of SmsCampaignRunner.
 *
 * WHY. The WhatsApp campaign runtime (runCampaignNowPaced) only speaks the Node
 * WhatsApp transports; it can't reach the mail bridge. So a campaign whose
 * sender is provider='email' is intercepted at the top of runCampaignNowPaced
 * and run here instead. It REUSES the campaign builder, audience, scheduling,
 * pacing and the campaign index/detail analytics — only the transport differs.
 * Per-recipient status lands in wp_campaign_contacts (so recomputeAggregates()
 * + the detail page light up), each send is mirrored into the unified inbox,
 * and email volume is NEVER billed to the WhatsApp wallet — it has its own plan
 * cap (email_monthly_limit).
 *
 * SENDER RESOLUTION. campaigns.device_id carries the WorkspaceEmailAccount
 * MIRROR ROW id (that is what the sender picker keys on: 'email:<mirrorRowId>').
 * The bridge's send endpoint wants the MailTrixy account id, so the mirror is
 * resolved first and its mailtrixy_account_id handed to the client. Mixing the
 * two ids up sends from the wrong mailbox (or nothing at all).
 *
 * SUBJECT. The builder's "Header" field (wp_campaigns.custom_header) doubles as
 * the mail Subject on this channel; a blank header falls back to the campaign
 * name so a message is never sent with an empty subject line.
 *
 * THREADING NOTE. Inbound/flow email threads key raw_jid as
 * 'email:<mirrorRowId>:<mtxConversationId>'. A campaign has no MailTrixy
 * conversation yet (the bridge's one-off send endpoint returns no thread id),
 * so the third segment here is the RECIPIENT ADDRESS instead. BOTH readers of
 * that key understand the shape: WorkspaceEmailAccount::forConversation (the
 * mailbox lookup, which only needs 3 segments and a numeric mirror row id) and
 * InboxDispatcher::dispatchMailtrixy, which sends an operator's reply on such a
 * thread as a fresh one-off from the same mailbox instead of a conversation
 * reply. So a campaign thread IS replyable, and every campaign to the same
 * address collapses onto one thread.
 */
class EmailCampaignRunner
{
    /** @param array<int,int> $contactIds recipients resolved by the campaign store/dispatch */
    public function run(WpCampaign $campaign, array $contactIds): array
    {
        // ONE RUNNER PER CAMPAIGN. store() creates an immediate campaign with
        // status='running' and last_run_at NULL — precisely what the sweeper's
        // stall rescue hunts for. Unguarded, a sweep pass (every campaigns-page
        // load, every Node heartbeat) re-arms the campaign while this loop is
        // still sending and fires a SECOND runner on the recipients this one has
        // not reached yet: the same person mailed twice, the plan cap charged
        // twice. The lock comfortably outlives the run's wall-clock budget, yet
        // expires soon enough that a hard-killed worker never strands a campaign.
        $lock = Cache::lock('email-campaign-' . $campaign->id, 180);
        if (! $lock->get()) {
            Log::warning('[EMAIL-CAMPAIGN] duplicate run skipped — already sending', ['campaign_id' => $campaign->id]);

            return ['ok' => false, 'error' => __('This campaign is already sending.')];
        }

        try {
            return $this->runLocked($campaign, $contactIds);
        } finally {
            optional($lock)->release();
        }
    }

    /** The send itself. ALWAYS called with the per-campaign lock held. */
    private function runLocked(WpCampaign $campaign, array $contactIds): array
    {
        $wsId = (int) $campaign->workspace_id;
        $ws   = Workspace::find($wsId);

        // Heartbeat immediately: the stall sweep reads last_run_at NULL (what
        // store() leaves) or older than 45s as a dead run and re-arms it.
        $campaign->forceFill(['last_run_at' => now()])->save();

        // Plan feature gate — the same crown the sibling channels enforce. The
        // platform toggle says the CHANNEL exists; the plan flag says whether
        // THIS workspace may send on it.
        if (! \App\Services\PlanLimitGuard::hasFeature($ws, 'access_email')) {
            $campaign->forceFill(['status' => 'failed'])->save();
            Log::warning('[EMAIL-CAMPAIGN] plan does not include email', ['campaign_id' => $campaign->id, 'workspace_id' => $wsId]);

            return ['ok' => false, 'error' => __('Your plan does not include Email.')];
        }

        // The sender is a mirror row — must belong to THIS workspace and still
        // be connected, else a stale/foreign device_id would send from another
        // tenant's mailbox.
        $mirror = $campaign->device_id
            ? WorkspaceEmailAccount::query()
                ->forWorkspace($wsId)
                ->connected()
                ->whereKey((int) $campaign->device_id)
                ->first()
            : null;

        $client = MailtrixyClient::fromSettings();

        if (! $mirror || (int) $mirror->mailtrixy_account_id <= 0 || ! $client->isConfigured()) {
            $campaign->forceFill(['status' => 'failed'])->save();
            Log::warning('[EMAIL-CAMPAIGN] no sendable mailbox', [
                'campaign_id' => $campaign->id, 'device_id' => $campaign->device_id,
            ]);

            return ['ok' => false, 'error' => setup_hint(
                __('This campaign has no connected mailbox. Link a mailbox on the devices page, then pick it as the campaign sender.'),
                __('Email sending is not available yet. Please contact support.')
            )];
        }

        // Email-only plan cap — never the WhatsApp wallet. Resolved from the
        // workspace id (WpCampaign has no `workspace` relation), so the cap is
        // actually enforced instead of silently short-circuiting on null.
        try {
            \App\Services\PlanLimitGuard::check($ws, 'email_monthly_limit', $this->monthlyOutbound($wsId));
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            $campaign->forceFill(['status' => 'failed'])->save();
            Log::warning('[EMAIL-CAMPAIGN] plan limit reached: ' . $e->getMessage(), ['campaign_id' => $campaign->id]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Body — a template's plain-text body, else the free-text custom_message.
        $bodyRaw = $campaign->template_id
            ? (string) (optional(WaTemplate::find($campaign->template_id))->template_body ?? '')
            : (string) ($campaign->custom_message ?? '');

        // Subject — the builder's Header field, falling back to the campaign name.
        $subjectRaw = trim((string) ($campaign->custom_header ?? ''));
        if ($subjectRaw === '') {
            $subjectRaw = trim((string) ($campaign->campaign_name ?? ''));
        }

        $recipients = $this->recipients($wsId, $contactIds);

        // DUPLICATE-ADDRESS GUARD (mail each address ONCE per run) — the twin of
        // the WhatsApp runtime's duplicate-number guard. Two contact rows holding
        // the SAME address is routine here: the inbound ingest auto-creates one
        // from the sender, a later import creates another, and contacts.email is
        // an encrypted cast so nothing dedupes it at import either. Unguarded the
        // person gets the campaign twice and the plan cap is charged twice. Keep
        // the first row; stamp the rest terminally 'skipped' — not a send
        // failure — so the run still completes and the counts stay honest.
        $seen   = [];
        $dupIds = [];
        $recipients = array_values(array_filter($recipients, function ($c) use (&$seen, &$dupIds) {
            $addr = strtolower(trim((string) $c['email']));
            if (isset($seen[$addr])) { $dupIds[] = (int) $c['id']; return false; }
            $seen[$addr] = true;
            return true;
        }));
        if (! empty($dupIds)) {
            WpCampaignContact::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('contact_id', $dupIds)
                ->whereNotIn('status', ['sent', 'delivered', 'read', 'responded', 'unsubscribed'])
                ->update(['status' => 'skipped', 'error_message' => __('Duplicate email address — already sent on this run')]);
            Log::info('[EMAIL-CAMPAIGN] duplicate-address guard skipped rows', [
                'campaign_id' => $campaign->id, 'skipped' => count($dupIds),
            ]);
        }

        if (empty($recipients)) {
            $campaign->forceFill(['status' => 'failed'])->save();
            $this->markDropped($campaign, $contactIds, []);

            return ['ok' => false, 'error' => __('No recipients with an email address')];
        }

        // Audience members recipients() dropped — opted out, or holding no email
        // address — can never be reached on this run. Stamp their pre-created
        // 'queued' pivot rows terminally so they don't read as stuck recipients.
        $this->markDropped($campaign, $contactIds, array_column($recipients, 'id'));

        $resolver = app(TemplateOverrideResolver::class);
        $hasOutboundCol = Schema::hasColumn('conversations', 'last_outbound_at');

        // Operator auto-end date — an absolute hard stop, so nothing may leave
        // after it. Checked on entry and again on every iteration.
        $deadlineAt = null;
        if (! empty($campaign->expires_at)) {
            try { $deadlineAt = \Illuminate\Support\Carbon::parse($campaign->expires_at); }
            catch (\Throwable $e) { $deadlineAt = null; }
        }
        if ($deadlineAt && $deadlineAt->isPast()) {
            return $this->endExpired($campaign);
        }

        // Wall-clock budget. This loop runs in afterResponse() (or a sweep tick)
        // and PHP-FPM hard-kills the worker after ~30-120s whatever
        // set_time_limit(0) says — at bridge latency a few dozen recipients
        // already exceed it. Stop cleanly and re-arm instead; the sweeper resumes
        // the rest (its reload excludes recipients already sent).
        $runStart       = time();
        $maxRunSec      = 20;
        $beat           = $runStart;
        $stoppedForTime = false;
        $operatorHalted = false;

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $cr) {
            // Operator hit Cancel/Pause while this batch was mid-flight — stop
            // NOW, and the guard after the loop leaves THEIR status alone instead
            // of clobbering it back to 'completed'. $campaign was loaded before
            // the loop, so read the LIVE status (cheap indexed lookup).
            if (in_array(WpCampaign::where('id', $campaign->id)->value('status'), ['cancelled', 'paused'], true)) {
                $operatorHalted = true;
                break;
            }

            // Crossed the auto-end date mid-run — end it, send nothing more.
            if ($deadlineAt && $deadlineAt->isPast()) {
                $this->endExpired($campaign);

                return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'expired' => true];
            }

            // Out of budget — hand the rest back to the sweeper below.
            if (time() - $runStart >= $maxRunSec) {
                $stoppedForTime = true;
                break;
            }

            // Keep the campaign out of the 45s stall sweep while the loop is alive.
            if (time() - $beat >= 10) {
                $beat = time();
                WpCampaign::where('id', $campaign->id)->update(['last_run_at' => now()]);
            }

            // Idempotent resume — a recipient already stamped terminally on a
            // PRIOR chunk is never mailed again.
            $prior = (string) WpCampaignContact::query()
                ->where('campaign_id', $campaign->id)->where('contact_id', (int) $cr['id'])->value('status');
            if (in_array($prior, ['sent', 'delivered', 'read', 'responded', 'unsubscribed', 'skipped'], true)) {
                continue;
            }

            $address = strtolower(trim((string) $cr['email']));
            $body    = $this->personalize($bodyRaw, $cr, $wsId, $resolver);
            $subject = $this->personalize($subjectRaw, $cr, $wsId, $resolver);

            // HEADER-INJECTION GUARD. The subject becomes a real mail header on
            // the far side, and a CR/LF inside it opens a second one ('Bcc: ...').
            // Runs AFTER substitution because the newline arrives through the
            // resolved contact value, not the stored template.
            $subject = trim((string) preg_replace('/[\r\n]+/', ' ', $subject));
            if (mb_strlen($subject) > 255) {
                $subject = mb_substr($subject, 0, 255);
            }

            if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL) || trim($body) === '') {
                $failed++;
                $this->markRecipient($campaign, (int) $cr['id'], 'failed', '', __('Invalid email address or empty message'));
                continue;
            }
            if (trim($subject) === '') {
                $subject = __('Message');
            }

            // A campaign body is plain text; escape it before it becomes HTML mail
            // so an ampersand or a stray angle bracket can't break (or inject into)
            // the rendered message. $text keeps the raw body for the plain part.
            $html = nl2br(e($body), false);

            $res = $client->send((int) $mirror->mailtrixy_account_id, $address, $subject, $html, $body);

            if (! empty($res['ok'])) {
                $sent++;
                $mid = (string) ($res['message_id'] ?? '');
                $this->markRecipient($campaign, (int) $cr['id'], 'sent', $mid);
                $this->mirrorToInbox($mirror, $wsId, $address, $subject, $body, $mid, $hasOutboundCol);
            } else {
                $failed++;
                $this->markRecipient($campaign, (int) $cr['id'], 'failed', '', (string) ($res['error'] ?? __('Email send failed')));
            }
        }

        // Operator Cancel/Pause landed mid-run — honour it: keep THEIR status and
        // never re-arm (re-arming is the "paused campaign auto-restarts" bug).
        if ($operatorHalted) {
            try { $campaign->recomputeAggregates(); } catch (\Throwable $e) { /* KPIs best-effort */ }
            Log::info('[EMAIL-CAMPAIGN] halted by operator', [
                'campaign_id' => $campaign->id, 'sent' => $sent, 'failed' => $failed,
            ]);

            return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'halted' => true];
        }

        // Out of wall-clock with recipients still to go — re-arm so the sweeper
        // resumes the rest, exactly like the WhatsApp paced runtime. Recurring
        // campaigns advance their own cadence in fireScheduledCampaign, so they
        // are never re-armed here.
        if ($stoppedForTime && $campaign->schedule_type !== 'recurring') {
            try {
                $next = \Illuminate\Support\Carbon::now($campaign->timezone ?: config('app.timezone', 'UTC'));
            } catch (\Throwable $e) {
                $next = \Illuminate\Support\Carbon::now('UTC');
            }
            $campaign->forceFill([
                'status'        => 'scheduled',
                // The sweeper only fires scheduled/recurring; an immediate send
                // keeps schedule_type='now', so flip it or the rest never resumes.
                'schedule_type' => 'scheduled',
                'send_date'     => $next->toDateString(),
                'send_time'     => $next->format('H:i:s'),
                'last_run_at'   => now(),
            ])->save();
            try { $campaign->recomputeAggregates(); } catch (\Throwable $e) { /* KPIs best-effort */ }
            Log::info('[EMAIL-CAMPAIGN] chunk done — re-armed for the remaining recipients', [
                'campaign_id' => $campaign->id, 'sent' => $sent, 'failed' => $failed,
            ]);

            return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'rearmed' => true];
        }

        // Roll the per-recipient log into the campaign KPI columns + finalize status.
        $campaign->forceFill([
            'status'       => $failed > 0 ? ($sent > 0 ? 'completed_with_errors' : 'failed') : 'completed',
            'completed_at' => now(),
        ])->save();
        try { $campaign->recomputeAggregates(); } catch (\Throwable $e) { /* KPIs best-effort */ }

        Log::info('[EMAIL-CAMPAIGN] done', [
            'campaign_id' => $campaign->id, 'sent' => $sent, 'failed' => $failed,
            'mailbox_id'  => $mirror->id,
        ]);

        return ['ok' => true, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Recipients as { id, email, phone, name, ...attrs }, opted-out + address-less
     * dropped.
     *
     * contacts.email is an 'encrypted' cast — the ciphertext differs per row, so
     * it can NEVER be filtered/compared in SQL. Hydrate the rows and drop the
     * blank ones in PHP.
     */
    private function recipients(int $wsId, array $contactIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        if (empty($ids)) return [];

        return Contact::query()->where('workspace_id', $wsId)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('is_unsubscribed')->orWhere('is_unsubscribed', false))
            ->get(['id', 'country_code', 'mobile', 'name', 'first_name', 'last_name', 'email', 'custom_attributes'])
            ->map(function ($c) {
                $cc    = preg_replace('/\D+/', '', (string) ($c->country_code ?? ''));
                $local = preg_replace('/\D+/', '', (string) ($c->mobile ?? ''));
                $phone = $cc && $local && strpos($local, $cc) !== 0 ? $cc . $local : $local;
                return [
                    'id'                => $c->id,
                    'phone'             => $phone,
                    'name'              => (string) ($c->name ?? ''),
                    'first_name'        => (string) ($c->first_name ?? ''),
                    'last_name'         => (string) ($c->last_name ?? ''),
                    'email'             => trim((string) ($c->email ?? '')),
                    'custom_attributes' => is_array($c->custom_attributes) ? $c->custom_attributes : [],
                ];
            })->filter(fn ($c) => $c['email'] !== '')->values()->all();
    }

    private function personalize(string $raw, array $cr, int $wsId, TemplateOverrideResolver $resolver): string
    {
        if ($raw === '' || ! str_contains($raw, '{{')) return $raw;
        return (string) preg_replace_callback(
            TemplateOverrideResolver::TOKEN_RE,
            fn ($m) => $resolver->lookup(trim((string) $m[1]), $cr, $wsId),
            $raw
        );
    }

    /** Update the recipient's wp_campaign_contacts row (pre-created in store()). */
    private function markRecipient(WpCampaign $campaign, int $contactId, string $status, string $mid = '', string $error = ''): void
    {
        try {
            $row = WpCampaignContact::query()
                ->where('campaign_id', $campaign->id)->where('contact_id', $contactId)->first();
            $data = ['status' => $status];
            if ($mid !== '')   $data['whatsapp_message_id'] = $mid;
            if ($status === 'sent') $data['sent_at'] = now();
            if ($error !== '') $data['error_message'] = mb_substr($error, 0, 191);
            if ($row) {
                $row->forceFill($data)->save();
            } else {
                WpCampaignContact::create($data + ['campaign_id' => $campaign->id, 'contact_id' => $contactId]);
            }
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-CAMPAIGN] recipient update failed: ' . $e->getMessage(), ['campaign_id' => $campaign->id, 'contact_id' => $contactId]);
        }
    }

    /**
     * Terminally stamp the audience members recipients() dropped.
     *
     * Their pivot rows were pre-created 'queued' by store(); left that way each
     * one reads as a stuck "Queued" recipient, keeps reconcileStatus() from ever
     * finishing the campaign, and (for opt-outs) never reaches the Opt-outs tab —
     * which reads the PIVOT flag, not the contact flag.
     *
     * Two reasons, two stamps: an explicit unsubscribe is 'unsubscribed'
     * (+ is_unsubscribed, so it counts as an opt-out); a contact who simply has
     * no email address is 'skipped'. Both are terminal, and neither counts as a
     * send failure in the KPI aggregate.
     *
     * @param array<int,int> $keptIds contact ids that DID resolve an address
     */
    private function markDropped(WpCampaign $campaign, array $contactIds, array $keptIds): void
    {
        try {
            $kept    = array_map('intval', $keptIds);
            $dropped = array_values(array_diff(array_unique(array_map('intval', $contactIds)), $kept));
            if (empty($dropped)) return;

            $terminal = ['sent', 'delivered', 'read', 'responded', 'unsubscribed', 'skipped'];

            // is_unsubscribed is a plain boolean column (not encrypted), so it is
            // safe to resolve the opt-outs in SQL.
            $optedOut = Contact::query()->where('workspace_id', (int) $campaign->workspace_id)
                ->whereIn('id', $dropped)->where('is_unsubscribed', true)->pluck('id')->all();

            if (! empty($optedOut)) {
                WpCampaignContact::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereIn('contact_id', $optedOut)
                    ->whereNotIn('status', $terminal)
                    ->update(['status' => 'unsubscribed', 'is_unsubscribed' => true]);
            }

            $noAddress = array_values(array_diff($dropped, array_map('intval', $optedOut)));
            if (! empty($noAddress)) {
                WpCampaignContact::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereIn('contact_id', $noAddress)
                    ->whereNotIn('status', $terminal)
                    ->update(['status' => 'skipped', 'error_message' => __('No email address on this contact')]);
            }
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-CAMPAIGN] drop stamp failed: ' . $e->getMessage(), ['campaign_id' => $campaign->id]);
        }
    }

    /**
     * End a campaign that passed its auto-end date: stamp every recipient it
     * never reached, then finalize. Mirrors the WhatsApp runtime's
     * endExpiredCampaign so an expired email blast reads the same on the detail
     * page instead of sitting 'running' forever.
     */
    private function endExpired(WpCampaign $campaign): array
    {
        try {
            WpCampaignContact::query()
                ->where('campaign_id', $campaign->id)
                ->whereNotIn('status', ['sent', 'delivered', 'read', 'responded', 'unsubscribed', 'skipped'])
                ->update(['status' => 'failed', 'error_message' => __('Not sent — the campaign passed its auto-end date'), 'updated_at' => now()]);
            $campaign->recomputeAggregates();
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-CAMPAIGN] expiry stamp failed: ' . $e->getMessage(), ['campaign_id' => $campaign->id]);
        }

        $failedCount = (int) WpCampaignContact::query()
            ->where('campaign_id', $campaign->id)->where('status', 'failed')->count();
        $campaign->forceFill([
            'status'       => (int) $campaign->sent_count > 0 ? 'completed' : 'failed',
            'failed_count' => $failedCount,
            'completed_at' => now(),
        ])->save();

        Log::info('[EMAIL-CAMPAIGN] auto-ended past its end date', [
            'campaign_id' => $campaign->id, 'unsent' => $failedCount,
        ]);

        return ['ok' => true, 'sent' => (int) $campaign->sent_count, 'failed' => $failedCount, 'expired' => true];
    }

    /** Mirror a sent campaign email into the unified inbox thread. */
    private function mirrorToInbox(WorkspaceEmailAccount $mirror, int $wsId, string $address, string $subject, string $body, string $mid, bool $hasOutboundCol): void
    {
        try {
            // provider MUST be in the create attributes: InboxMessage auto-stamps
            // its provider from the parent conversation on create, so a thread
            // created without one would stamp the workspace's WhatsApp engine.
            $convo = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'email', 'raw_jid' => 'email:' . $mirror->id . ':' . $address],
                ['title' => $address, 'provider' => 'email', 'origin' => 'email', 'status' => 'pending',
                 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => null],
            );
            InboxMessage::create([
                'conversation_id' => $convo->id, 'provider' => 'email', 'direction' => 'out',
                'body' => $body, 'status' => 'sent',
                'meta' => ['wa_message_id' => $mid, 'email' => array_filter([
                    'subject'        => $subject,
                    'to'             => $address,
                    'mtx_account_id' => (int) $mirror->mailtrixy_account_id,
                    'mtx_message_id' => $mid,
                    'source'         => 'campaign',
                ], fn ($v) => $v !== null && $v !== '')],
                'sent_at' => now(),
            ]);
            $update = ['last_message_at' => now(), 'preview' => Str::limit($body, 120)];
            if ($hasOutboundCol) {
                $update['last_outbound_at'] = now();
            }
            $convo->forceFill($update)->save();
        } catch (\Throwable $e) {
            Log::warning('[EMAIL-CAMPAIGN] inbox mirror failed: ' . $e->getMessage());
        }
    }

    private function monthlyOutbound(int $wsId): int
    {
        return (int) InboxMessage::query()
            ->where('inbox_messages.provider', 'email')->where('inbox_messages.direction', 'out')
            ->join('conversations', 'conversations.id', '=', 'inbox_messages.conversation_id')
            ->where('conversations.workspace_id', $wsId)
            ->where('inbox_messages.created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
