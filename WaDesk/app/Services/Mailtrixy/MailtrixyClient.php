<?php

namespace App\Services\Mailtrixy;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;

/**
 * The WaDesk → MailTrixy bridge.
 *
 * MailTrixy runs as its OWN deployment and owns the real email engine
 * (mailbox OAuth/IMAP, sending, sync). WaDesk never talks to a mail provider
 * directly — it talks to MailTrixy, and MailTrixy's mail surfaces in WaDesk's
 * unified inbox.
 *
 * The two prove they share a secret on every call via the X-Mailtrixy-Secret
 * header — the SAME secret the admin pastes on the Add-ons "Connect" card
 * (stored encrypted in SystemSetting). One direction lives here (WaDesk
 * calling MailTrixy); the reverse (MailTrixy pushing new inbound mail to
 * WaDesk) lands on POST /api/mailtrixy/inbound, guarded by the same secret.
 *
 * Contract (MailTrixy must expose, all under {url}, secret in the header):
 *   GET  /api/wadesk/handshake  → {ok:true, service:"mailtrixy", brand:"…"}
 *   GET  /api/wadesk/accounts   → {ok:true, accounts:[{id,workspace_id,email,name,provider,status}]}   (?owner_email= scopes to that person's mailboxes)
 *   POST /api/wadesk/reply      → {ok:true, message_id, delivery_status}   body:{conversation_id,text,html}
 *   POST /api/wadesk/send       → {ok:true}                                body:{account_id,to,subject,html,text}
 */
class MailtrixyClient
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $secret  = '',
    ) {}

    /** Build from the admin-saved connection (URL + shared secret). */
    public static function fromSettings(): self
    {
        return new self(
            rtrim((string) SystemSetting::get('mailtrixy_url', ''), '/'),
            (string) SystemSetting::get('mailtrixy_secret', ''),
        );
    }

    /** Is a connection even configured? (URL + secret both present.) */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->secret !== '';
    }

    /** Public base URL of the connected MailTrixy deployment (for deep-links
     *  into its mailbox UI). Empty when not configured. */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Was the last saved handshake successful? Cheap — reads the stored flag. */
    public function isConnected(): bool
    {
        return $this->isConfigured() && (bool) SystemSetting::get('mailtrixy_connected', false);
    }

    /** Live handshake — proves reachability + secret match right now. */
    public function handshake(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            $r = $this->request()->get('/api/wadesk/handshake');
            return $r->successful() && ($r->json('ok') === true || $r->json('service') === 'mailtrixy');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array> Connected email accounts on the MailTrixy side
     * ([{id,workspace_id,email,name,provider,status}]). Unwraps the
     * {ok,accounts} envelope; degrades to [] on any failure — callers render
     * an empty list rather than an error.
     *
     * $ownerEmail scopes the list to mailboxes owned on MailTrixy by the user
     * with that email (same owner-matching rule as InstaflowClient::accounts),
     * so the "link existing" picker never surfaces another tenant's mailboxes.
     */
    public function accounts(?string $ownerEmail = null): array
    {
        if (! $this->isConfigured()) {
            return [];
        }
        try {
            $params = ($ownerEmail ?? '') !== '' ? ['owner_email' => $ownerEmail] : [];
            $r = $this->request()->get('/api/wadesk/accounts', $params);
            $j = $r->successful() ? $r->json() : null;
            return is_array($j['accounts'] ?? null) ? $j['accounts'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * PULL one page of inbound mail for a mailbox, oldest-first.
     *
     * The push path is live-only and fire-and-forget (8s timeout, no retry), so
     * mail that predates the link is never announced and a blip loses a message
     * for good. This pulls instead — used for the first backfill after linking
     * and as a repair sweep afterwards.
     *
     * Each entry is already shaped like a push payload, so the caller can feed
     * it through the SAME ingest a live push uses.
     *
     * @return array{ok:bool, messages:array<int,array>, next_after_id:?int, error?:string}
     */
    public function messages(int $accountId, ?string $ownerEmail = null, ?int $afterId = null, int $limit = 50): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'messages' => [], 'next_after_id' => null, 'error' => 'not_configured'];
        }
        try {
            $params = array_filter([
                'account_id'  => $accountId,
                'owner_email' => ($ownerEmail ?? '') !== '' ? $ownerEmail : null,
                'after_id'    => $afterId,
                'limit'       => $limit,
            ], fn ($v) => $v !== null);

            $r = $this->request()->get('/api/wadesk/messages', $params);
            if (! $r->successful()) {
                return ['ok' => false, 'messages' => [], 'next_after_id' => null,
                        'error' => 'http_' . $r->status()];
            }
            $j = $r->json();
            return [
                'ok'            => (bool) ($j['ok'] ?? false),
                'messages'      => is_array($j['messages'] ?? null) ? $j['messages'] : [],
                'next_after_id' => $j['next_after_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'messages' => [], 'next_after_id' => null,
                    'error' => mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /**
     * Reply on a MailTrixy conversation. MailTrixy creates the outbound
     * Message row on that thread and performs the SMTP send (threading stays
     * correct, so both inboxes agree). Returns the decoded
     * {ok, message_id, delivery_status} envelope, or {ok:false, error}.
     */
    public function reply(string|int $mtxConversationId, ?string $text, ?string $html = null): array
    {
        return $this->post('/api/wadesk/reply', array_filter([
            'conversation_id' => (int) $mtxConversationId,
            'text'            => $text,
            'html'            => $html,
        ], fn ($v) => $v !== null));
    }

    /**
     * One-off send from a MailTrixy account (no existing thread) — used by
     * campaigns later. Returns the decoded {ok} envelope, or {ok:false, error}.
     */
    public function send(int $accountId, string $to, string $subject, string $html, ?string $text = null): array
    {
        return $this->post('/api/wadesk/send', array_filter([
            'account_id' => $accountId,
            'to'         => $to,
            'subject'    => $subject,
            'html'       => $html,
            'text'       => $text,
        ], fn ($v) => $v !== null));
    }

    /** Shared POST → ['ok'=>bool, ...] envelope; never throws into the caller. */
    private function post(string $path, array $body): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => __(':brand is not connected.', ['brand' => mailtrixy_brand_name()])];
        }
        try {
            $r = $this->request()->post($path, $body);
            if (! $r->successful()) {
                return ['ok' => false, 'error' => mailtrixy_brand_name() . ' returned HTTP ' . $r->status()];
            }
            $j = $r->json();
            return is_array($j) ? $j : ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Shared pending HTTP client — secret header + JSON + sane timeout on every call. */
    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-Mailtrixy-Secret' => $this->secret])
            ->acceptJson()
            ->timeout(15);
    }
}
