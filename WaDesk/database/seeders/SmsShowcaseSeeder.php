<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WaProviderConfig;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds a realistic SMS demo so the channel renders on first load — the channel
 * turned on with the plan feature granted, a connected Twilio SMS number
 * (WaProviderConfig provider='sms', the SAME store the connect flow writes), and
 * a spread of SMS threads in the UNIFIED inbox (channel='sms', raw_jid
 * 'sms:<digits>' — exactly what SmsWebhookController writes).
 *
 * The SMS analogue of TelegramShowcaseSeeder. Idempotent: the sender is
 * updateOrCreate'd; demo threads are wiped and re-created on re-run. Credentials
 * are inert (demo) so a real send fails soft — nothing leaks.
 *
 *   php artisan db:seed --class=Database\\Seeders\\SmsShowcaseSeeder
 */
class SmsShowcaseSeeder extends Seeder
{
    private const DEMO_FROM       = '+17372508034';   // demo US Twilio SMS number (display only)
    private const DEMO_MSG91_FROM = '+919000000001';  // demo India MSG91 sender number (display only)

    public function run(): void
    {
        // Self-contained: turn the channel on and grant the plan feature/quota so
        // the nav item + /sms page + connect card are reachable on any plan.
        SystemSetting::set('sms_enabled', true, 'bool', 'SMS demo (seeded).');
        if (Package::query()->exists()) {
            $update = ['access_sms' => true];
            if (Schema::hasColumn('packages', 'sms_monthly_limit')) {
                $update['sms_monthly_limit'] = 5000;
            }
            Package::query()->update($update);
        }

        // Seed EVERY workspace (optionally scoped by SMS_SEED_WS) so the SMS
        // channel shows wherever the operator is — the filter is gated on a
        // connected number in THAT workspace, so a single-workspace seed left
        // every other workspace without the tab.
        $only = (int) env('SMS_SEED_WS', 0);
        $workspaces = $only
            ? Workspace::query()->whereKey($only)->get()
            : Workspace::query()->orderBy('id')->get();

        if ($workspaces->isEmpty()) {
            $this->command?->warn('[SMS-SEED] No workspace found — nothing to seed.');

            return;
        }

        foreach ($workspaces as $ws) {
            $wsId = (int) $ws->id;

            // TWO numbers so "multiple accounts" is demonstrable: a Twilio (global)
            // number and an MSG91 (India / DLT) sender.
            $twilio = $this->seedSender($wsId, self::DEMO_FROM, 'twilio', 'SMS · Twilio', [
                'account_sid' => 'ACdemo' . Str::random(26),
                'auth_token'  => 'demo_' . Str::random(28),
            ], ['sms_provider' => 'twilio', 'rate_per_segment' => '0.0075', 'currency' => 'USD']);

            $this->seedSender($wsId, self::DEMO_MSG91_FROM, 'msg91', 'SMS · MSG91 (India)', [
                'auth_token' => 'demo_msg91_' . Str::random(24),
            ], ['sms_provider' => 'msg91', 'sender_id' => 'WADESK', 'dlt_template_id' => '1707100000000000000', 'rate_per_segment' => '0.15', 'currency' => 'INR']);

            $this->seedInbox($wsId, $twilio);
        }

        $this->command?->info("[SMS-SEED] SMS demo seeded for {$workspaces->count()} workspace(s) — Twilio + MSG91 numbers each.");
    }

    /** A connected demo SMS number (inert creds — real sends fail soft). */
    private function seedSender(int $wsId, string $from, string $provider, string $label, array $creds, array $meta): WaProviderConfig
    {
        $cfg = WaProviderConfig::updateOrCreate(
            ['workspace_id' => $wsId, 'provider' => 'sms', 'phone_number' => $from],
            [
                'status'        => WaProviderConfig::STATUS_CONNECTED,
                'display_label' => $label,
                'is_primary'    => false,
                'connected_at'  => now()->subDays(3),
                'meta_json'     => $meta,
            ]
        );
        // Inert demo credentials (setCreds is not mass-assignable) — a real send
        // fails soft with a credential error, exactly like an unconfigured number.
        $cfg->setCreds($creds + ['from_number' => $from]);
        $cfg->save();

        return $cfg;
    }

    /** Unified-inbox demo SMS threads (channel='sms', raw_jid 'sms:<digits>'). */
    private function seedInbox(int $wsId, WaProviderConfig $sender): void
    {
        // Wipe prior demo SMS threads for a clean re-run.
        $priorIds = Conversation::query()->where('workspace_id', $wsId)
            ->where('channel', 'sms')->pluck('id');
        if ($priorIds->isNotEmpty()) {
            InboxMessage::whereIn('conversation_id', $priorIds)->delete();
            Conversation::whereIn('id', $priorIds)->delete();
        }

        $now = now();

        // [customerDigits, name, unread, [ [dir, body, minutesAgo], ... ]]
        // Kept RECENT (last message < 15 min) so the demo threads land in the
        // inbox's first loaded page — the channel rail filters the loaded queue.
        $threads = [
            ['15551230011', 'Priya Sharma', 1, [
                ['in',  'Hi, is my order shipped yet?', 12],
                ['out', 'Hi Priya! Yes — it shipped this morning, tracking: 1Z99A. Arrives Fri.', 8],
                ['in',  'Great, thank you!', 2],
            ]],
            ['15551230022', 'Marcus Lee', 0, [
                ['out', 'Your appointment is confirmed for Tue 3pm. Reply C to cancel.', 14],
                ['in',  'C', 9],
                ['out', 'No problem — your appointment is cancelled. Reply BOOK to reschedule.', 6],
            ]],
            ['15551230033', 'Aisha Khan', 2, [
                ['in',  'Do you have the size M in stock?', 10],
                ['out', 'We do! Want me to reserve one for you?', 7],
                ['in',  'Yes please', 1],
            ]],
        ];

        foreach ($threads as [$digits, $name, $unread, $msgs]) {
            $lastMinAgo = (int) $msgs[array_key_last($msgs)][2];
            $last = $now->copy()->subMinutes($lastMinAgo);

            $conv = Conversation::create([
                'workspace_id'    => $wsId,
                'channel'         => 'sms',
                'raw_jid'         => 'sms:' . $digits,
                'title'           => $name,
                'provider'        => 'sms',
                'origin'          => 'sms',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'preview'         => Str::limit((string) $msgs[array_key_last($msgs)][1], 120),
                'unread_count'    => $unread,
                'last_message_at' => $last,
                'last_inbound_at' => $last,
                'contact_digits'  => $digits,
            ]);

            foreach ($msgs as [$dir, $body, $minAgo]) {
                $when = $now->copy()->subMinutes((int) $minAgo);
                InboxMessage::create([
                    'conversation_id' => $conv->id,
                    'provider'        => 'sms',
                    'direction'       => $dir,
                    'body'            => $body,
                    'from_number'     => $dir === 'in' ? '+' . $digits : null,
                    'status'          => $dir === 'in' ? 'received' : 'delivered',
                    'meta'            => ['sms' => array_filter([
                        'to' => $dir === 'in' ? $sender->phone_number : null,
                        'from' => $dir === 'in' ? '+' . $digits : $sender->phone_number,
                        'provider' => 'twilio', 'source' => 'demo',
                    ]), 'wa_message_id' => 'SM_demo_' . Str::random(12)],
                    'sent_at'         => $when,
                    'delivered_at'    => $when,
                    'created_at'      => $when,
                    'updated_at'      => $when,
                ]);
            }
        }
    }
}
