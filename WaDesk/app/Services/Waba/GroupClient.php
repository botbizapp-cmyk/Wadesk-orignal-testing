<?php

namespace App\Services\Waba;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp **Groups API** (Cloud API) client.
 *
 * One method per documented endpoint. Every method returns the same envelope
 * the other WABA services use:
 *
 *     ['ok' => bool, 'data' => array|null, 'error' => string|null, 'code' => int|null]
 *
 * so callers never have to know whether a failure came from Graph, the network,
 * or us.
 *
 * Two things about this API that shape the whole design:
 *
 *  1. **Eligibility.** The number must belong to an Official Business Account.
 *     Anything else gets 131215 and NO endpoint works. isEligible() probes once
 *     and caches, so the UI can explain rather than showing dead buttons.
 *
 *  2. **The invite link is not in the create response.** It arrives later on the
 *     `group_lifecycle_update` webhook. create() therefore returns a group id
 *     with no link, and that is the correct, expected result.
 *
 * There is deliberately no addParticipants(): Meta does not expose one. Groups
 * are invite-only by design — you create, get a link, and send it as a template.
 */
class GroupClient
{
    private string $base;
    private string $token;
    private string $phoneNumberId;

    /** Graph errors that mean "this account can never use Groups". */
    private const ERR_NOT_ELIGIBLE = 131215;

    /** Operator-readable text for the documented group error codes. */
    private const ERROR_TEXT = [
        131020 => 'A group needs more than one member before you can message it.',
        131041 => 'That group no longer exists, or this number is not a member of it.',
        131059 => 'The page cursor expired. Reload the list and try again.',
        131201 => 'Some participants in the request could not be processed.',
        131202 => 'The same participant was listed more than once.',
        131204 => 'The group is at its participant limit.',
        131207 => 'Meta has suspended this group for a policy violation.',
        131208 => 'Too many group operations from this number. Wait a moment and retry.',
        131209 => 'The group picture must be square.',
        131210 => 'The group picture must be at least 192 by 192 pixels.',
        131211 => 'This number has reached its limit for created groups.',
        131212 => 'That person is not a member of the group.',
        131213 => 'That join request no longer exists.',
        131214 => 'Meta has temporarily disabled group creation for this number.',
        self::ERR_NOT_ELIGIBLE => 'This number is not eligible for the Groups API. Meta requires an Official Business Account.',
    ];

    public function __construct(public WaProviderConfig $cfg)
    {
        $creds = $cfg->creds();
        $meta  = is_array($cfg->meta_json) ? $cfg->meta_json : [];

        $version = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');
        $this->base          = 'https://graph.facebook.com/' . ltrim($version, '/');
        $this->token         = (string) ($creds['access_token'] ?? '');
        $this->phoneNumberId = (string) ($meta['phone_number_id'] ?? $creds['phone_number_id'] ?? '');
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    /**
     * Create a group. The response carries the group id; the INVITE LINK does
     * not appear here — it arrives on the group_lifecycle_update webhook.
     *
     * @param string $subject     max 128 chars (Meta truncates beyond that)
     * @param string $description max 2048 chars
     * @param string $approval    approval_required | auto_approve
     */
    public function create(string $subject, string $description = '', string $approval = ''): array
    {
        $body = array_filter([
            'messaging_product'  => 'whatsapp',
            'subject'            => mb_substr(trim($subject), 0, 128),
            'description'        => $description !== '' ? mb_substr(trim($description), 0, 2048) : null,
            'join_approval_mode' => $approval !== '' ? $approval : null,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->post("{$this->base}/{$this->phoneNumberId}/groups", $body);
    }

    /**
     * Active groups for this number, newest page first.
     *
     * @param int    $limit 1–1024 (Meta's own bounds; default 25)
     * @param string $after opaque pagination cursor
     */
    public function list(int $limit = 25, string $after = ''): array
    {
        $limit = max(1, min(1024, $limit));
        $query = array_filter(['limit' => $limit, 'after' => $after ?: null]);

        return $this->get("{$this->base}/{$this->phoneNumberId}/groups", $query);
    }

    /** One group's metadata. Defaults to every documented field. */
    public function show(string $groupId, array $fields = []): array
    {
        $fields = $fields ?: [
            'subject', 'description', 'participants', 'join_approval_mode',
            'suspended', 'creation_timestamp', 'total_participant_count',
        ];

        return $this->get("{$this->base}/{$groupId}", ['fields' => implode(',', $fields)]);
    }

    /**
     * Update subject / description. Meta reports per-field success on the
     * group_settings_update webhook, so a 200 here does NOT mean every field
     * landed — a partial failure is a normal outcome.
     */
    public function update(string $groupId, array $fields): array
    {
        $body = ['messaging_product' => 'whatsapp'];
        if (isset($fields['subject'])) {
            $body['subject'] = mb_substr((string) $fields['subject'], 0, 128);
        }
        if (isset($fields['description'])) {
            $body['description'] = mb_substr((string) $fields['description'], 0, 2048);
        }

        return $this->post("{$this->base}/{$groupId}", $body);
    }

    /** Delete the group. Removes every participant, including this business. */
    public function delete(string $groupId): array
    {
        return $this->request('delete', "{$this->base}/{$groupId}", []);
    }

    // ── Invite link ─────────────────────────────────────────────────────────

    public function inviteLink(string $groupId): array
    {
        return $this->get("{$this->base}/{$groupId}/invite_link");
    }

    /** Reset the link. Every previously shared link stops working immediately. */
    public function resetInviteLink(string $groupId): array
    {
        return $this->post("{$this->base}/{$groupId}/invite_link", ['messaging_product' => 'whatsapp']);
    }

    // ── Participants ────────────────────────────────────────────────────────

    /**
     * Remove up to 8 participants per call (Meta's cap). A removed person can
     * no longer rejoin through an existing invite link.
     */
    public function removeParticipants(string $groupId, array $participants): array
    {
        $participants = array_values(array_filter(array_map('strval', $participants)));
        if (! $participants) {
            return ['ok' => false, 'data' => null, 'error' => 'No participants given.', 'code' => null];
        }
        if (count($participants) > 8) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'Meta allows at most 8 participants per removal request.',
                'code'  => null,
            ];
        }

        return $this->request('delete', "{$this->base}/{$groupId}/participants", [
            'messaging_product' => 'whatsapp',
            'participants'      => $participants,
        ]);
    }

    // ── Join requests (approval_required groups only) ───────────────────────

    public function joinRequests(string $groupId, int $limit = 25, string $after = ''): array
    {
        return $this->get("{$this->base}/{$groupId}/join_requests", array_filter([
            'limit' => max(1, min(1024, $limit)),
            'after' => $after ?: null,
        ]));
    }

    public function approveJoinRequests(string $groupId, array $ids): array
    {
        return $this->post("{$this->base}/{$groupId}/join_requests", [
            'messaging_product' => 'whatsapp',
            'join_requests'     => array_values(array_map('strval', $ids)),
        ]);
    }

    public function rejectJoinRequests(string $groupId, array $ids): array
    {
        return $this->request('delete', "{$this->base}/{$groupId}/join_requests", [
            'messaging_product' => 'whatsapp',
            'join_requests'     => array_values(array_map('strval', $ids)),
        ]);
    }

    // ── Eligibility ─────────────────────────────────────────────────────────

    /**
     * Can this number use Groups at all?
     *
     * Meta exposes no capability flag, so we probe the cheapest endpoint and
     * read the error. Cached for an hour because the answer is an account
     * property, not a per-request one — and because every screen would
     * otherwise re-probe on load.
     */
    public function isEligible(bool $fresh = false): bool
    {
        $key = 'waba_groups_eligible_' . $this->cfg->id;
        if (! $fresh && ($cached = cache()->get($key)) !== null) {
            return (bool) $cached;
        }

        $res = $this->list(1);
        // Only 131215 is a definitive "no". A network blip or rate limit must
        // NOT be cached as ineligible — that would hide the feature for an hour
        // over a transient failure.
        $eligible = ($res['ok'] ?? false) || (int) ($res['code'] ?? 0) !== self::ERR_NOT_ELIGIBLE;

        if (($res['ok'] ?? false) || (int) ($res['code'] ?? 0) === self::ERR_NOT_ELIGIBLE) {
            cache()->put($key, $eligible, now()->addHour());
        }

        return $eligible;
    }

    // ── HTTP plumbing ───────────────────────────────────────────────────────

    private function get(string $url, array $query = []): array
    {
        return $this->request('get', $url, $query);
    }

    private function post(string $url, array $body): array
    {
        return $this->request('post', $url, $body);
    }

    private function request(string $method, string $url, array $payload): array
    {
        if ($this->token === '' || $this->phoneNumberId === '') {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'This WhatsApp number is missing its Meta credentials. Reconnect it and try again.',
                'code'  => null,
            ];
        }

        try {
            $req = Http::withToken($this->token)->acceptJson()->timeout(20);
            $res = $method === 'get'
                ? $req->get($url, $payload)
                : $req->{$method}($url, $payload);   // post / delete both carry a JSON body

            if ($res->successful()) {
                return ['ok' => true, 'data' => (array) $res->json(), 'error' => null, 'code' => null];
            }

            $err  = (array) $res->json('error', []);
            $code = (int) ($err['code'] ?? 0);

            return [
                'ok'    => false,
                'data'  => null,
                // Prefer our own wording for the documented codes — Meta's text
                // is written for developers, not the operator reading it.
                'error' => self::ERROR_TEXT[$code] ?? (string) ($err['message'] ?? 'HTTP ' . $res->status()),
                'code'  => $code ?: null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[WABA-GROUPS] request failed', [
                'method' => $method, 'url' => $url, 'err' => $e->getMessage(),
            ]);

            return ['ok' => false, 'data' => null, 'error' => $e->getMessage(), 'code' => null];
        }
    }
}
