<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ONE THREAD PER NUMBER. The single place that answers "which conversation does
 * this phone number belong to?" — every inbound, outbound, webhook, catalog,
 * quick-send, extension and mobile-app path must go through here.
 *
 * ----------------------------------------------------------------------------
 * THE TWO RULES, AND WHY
 * ----------------------------------------------------------------------------
 *
 * 1. MATCH ON THE DIGITS, NEVER ON THE STRING.
 *    The same customer was stored as '919…', '919…@s.whatsapp.net' and
 *    '919…@lid' by different writers. Every lookup used raw string equality, so
 *    a thread written in one shape was invisible to code searching in another
 *    and a duplicate was created. `contact_digits` normalises all of them.
 *
 * 2. NOTHING ELSE MAY PARTITION THE LOOKUP — not `origin`, not `provider`,
 *    not `device_id`.
 *    - `origin` records where a thread STARTED ('inbox', 'chat', 'quick',
 *      'bulk'). Lookups each filtered on a different subset of those values, so
 *      a Quick Send thread ('quick') was invisible to the WhatsApp webhook
 *      (which only looked at 'inbox'/'chatbot') and both existed at once.
 *    - `provider` / `device_id` used to keep engines apart. They no longer do:
 *      the SAME customer number reaching a workspace on WABA and on the
 *      Unofficial API is ONE person and must be ONE thread. The conversation's
 *      provider/device_id follow the most recent message (see stampChannel), so
 *      a reply still goes back out over the channel last used.
 *
 *    EXCEPTION — MULTI-NUMBER WORKSPACES. When a workspace has 2+ connected
 *    business numbers, a customer texting BOTH must get one thread PER business
 *    number (the operator needs to see, and reply from, the number that was
 *    actually messaged). So when an inbound path passes the RECEIVING device id
 *    AND workspaceHasMultipleNumbers() is true, the lookup DOES partition on
 *    device_id, and stampChannel stops moving it. Single-number workspaces — the
 *    overwhelming majority — are entirely unaffected: no receiving id is acted
 *    on, so the behaviour is byte-for-byte the same as before.
 *
 * Anything else that needs to distinguish channels does it on the MESSAGE, not
 * by splitting the thread.
 */
class ConversationResolver
{
    /**
     * Normalise any phone / JID / LID into the identity used for matching.
     *
     * Returns null when the value carries no usable identity (widget visitors,
     * empty strings), so callers can tell "no identity" from "some identity".
     */
    public static function digitsFor(?string $jidOrPhone): ?string
    {
        $v = trim((string) $jidOrPhone);
        if ($v === '' || str_starts_with($v, 'widget-')) {
            return null;
        }

        // Instagram + Facebook + email (and any non-phone channel) JIDs carry
        // NO phone identity: `ig:<self>:<other>` (two 16–17 digit IGSIDs),
        // `fb:<pageId>:<psid>` / `fb:<pageId>:post:<postId>:<fromId>` (page id +
        // PSID / comment ids), `email:<mirrorRowId>:<mtxConversationId>` (two
        // row ids). Flattening them to digits both (a) collides the parts into
        // a number that can overflow contact_digits(32) — crashing the insert —
        // and (b) forges a phone identity: `email:91:9876543210` would stamp
        // '919876543210', merging the email thread with that real number's
        // WhatsApp thread in one-thread-per-number resolution. These threads
        // are keyed on the full raw_jid, never on digits — return null so the
        // column stays empty.
        if (str_starts_with($v, 'ig:') || str_starts_with($v, 'fb:') || str_starts_with($v, 'email:')) {
            return null;
        }

        // Namespace groups so a group id can never collide with a phone number.
        $isGroup = str_contains($v, '@g.us');

        // Drop the '@…' suffix BEFORE stripping non-digits, otherwise
        // 's.whatsapp.net' would contribute nothing but '@lid' vs '@g.us'
        // would become indistinguishable.
        $local  = str_contains($v, '@') ? strstr($v, '@', true) : $v;
        $digits = (string) preg_replace('/\D+/', '', $local);

        if ($digits === '') {
            return null;
        }

        return $isGroup ? 'g:' . $digits : $digits;
    }

    /**
     * Every legacy string shape a number could have been stored as, for rows
     * written before `contact_digits` existed (or by a path that bypassed the
     * model). Keeps the resolver correct on un-backfilled data.
     *
     * @return array<int, string>
     */
    public static function legacyShapes(string $digits): array
    {
        return [
            $digits,
            $digits . '@s.whatsapp.net',
            $digits . '@c.us',
            $digits . '@lid',
        ];
    }

    /**
     * Find the ONE conversation for this number in this workspace.
     *
     * Oldest wins: the lowest id is the thread carrying the real history, and
     * keeping it stable means deal links, audit rows and bookmarks stay valid.
     *
     * $receivingDeviceId — the business number that RECEIVED this message. Pass
     * it from the inbound webhooks so a MULTI-number workspace keeps a separate
     * thread per connected number (see resolveScoped). Leave null everywhere the
     * receiving number is unknown/irrelevant (Quick Send, catalog, mobile app) —
     * those keep the one-thread-per-customer behaviour unchanged.
     */
    public static function find(int $workspaceId, ?string $jidOrPhone, ?int $receivingDeviceId = null): ?Conversation
    {
        return self::resolveScoped($workspaceId, $jidOrPhone, $receivingDeviceId, false);
    }

    /**
     * Same as find(), but takes a row lock so two concurrent webhooks for the
     * same number cannot both miss and both create. Call inside a transaction.
     */
    public static function findForUpdate(int $workspaceId, ?string $jidOrPhone, ?int $receivingDeviceId = null): ?Conversation
    {
        return self::resolveScoped($workspaceId, $jidOrPhone, $receivingDeviceId, true);
    }

    /**
     * The shared body of find()/findForUpdate().
     *
     * When a $receivingDeviceId is supplied AND the workspace has 2+ connected
     * numbers, the thread is partitioned by the number that received the
     * message: prefer the thread already bound to THIS number, else adopt an
     * unbound (device_id NULL) thread — a Quick Send draft — so nothing is
     * duplicated, but NEVER match a thread bound to a DIFFERENT number. That is
     * what gives each business number its own conversation and makes a reply
     * leave from the number the customer wrote to. Single-number workspaces (and
     * any caller that passes no device) skip all of this — the lookup partitions
     * on nothing but the workspace + digits, exactly as before.
     */
    private static function resolveScoped(int $workspaceId, ?string $jidOrPhone, ?int $receivingDeviceId, bool $lock): ?Conversation
    {
        $digits = self::digitsFor($jidOrPhone);
        if ($workspaceId <= 0 || $digits === null) {
            return null;
        }

        // A WhatsApp GROUP is a SHARED entity — one thread per workspace, NEVER
        // partitioned by the receiving number. Two of the workspace's own numbers
        // both in the same group each receive every post, so the per-number split
        // below would create TWO threads for the one group (it "came twice — one
        // for admin, one for the member"), and a new post landing in the other
        // copy is why groups didn't update live. Groups skip the split entirely
        // and always resolve to the single workspace-wide thread (`g:<id>`).
        $isGroup = str_starts_with((string) $digits, 'g:');

        if (! $isGroup && $receivingDeviceId && self::workspaceHasMultipleNumbers($workspaceId)) {
            $exact = self::applyLock(
                self::matchQuery($workspaceId, $digits)->where('device_id', $receivingDeviceId)->orderBy('id'),
                $lock
            )->first();
            if ($exact) {
                return $exact;
            }
            // No thread bound to this number yet — adopt an unbound one if it
            // exists (bound on the first stampChannel), else signal "create".
            return self::applyLock(
                self::matchQuery($workspaceId, $digits)->whereNull('device_id')->orderBy('id'),
                $lock
            )->first();
        }

        return self::applyLock(self::matchQuery($workspaceId, $digits)->orderBy('id'), $lock)->first();
    }

    private static function applyLock(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    /**
     * Per-request cache of "does this workspace have 2+ connected sending
     * numbers?". The answer gates the per-number thread split. $numberCounter is
     * a test seam (and a hook for callers that already know the count); when
     * null it falls back to the live connected-sender count.
     */
    private static array $multiNumberCache = [];

    /** @var (\Closure(int):int)|null */
    public static ?\Closure $numberCounter = null;

    public static function workspaceHasMultipleNumbers(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }
        if (array_key_exists($workspaceId, self::$multiNumberCache)) {
            return self::$multiNumberCache[$workspaceId];
        }

        try {
            $count = self::$numberCounter
                ? (int) (self::$numberCounter)($workspaceId)
                : self::provisionedNumberCount($workspaceId);
        } catch (\Throwable $e) {
            $count = 0; // never let a counting problem break thread resolution
        }

        return self::$multiNumberCache[$workspaceId] = ($count > 1);
    }

    /**
     * Count the workspace's PROVISIONED (paired / set-up) sending numbers across
     * BOTH engines — NOT the momentary connected-sender count.
     *
     * The bug this fixes: the gate used to read WorkspaceEngine::senders()->count(),
     * which filters Baileys devices on status='connected'. Unofficial numbers flap
     * connected↔disconnected constantly (reconnects, 401 re-pairs, Node restarts),
     * so the instant an inbound arrived while a device was briefly down the count
     * dropped to 1 → the per-number thread partition collapsed (resolveScoped went
     * device-blind) AND stampChannel rebound conversations.device_id to the wrong
     * number → "multi-device Unified Inbox mixes chats". A paired Baileys device
     * (status connected OR disconnected) and a set-up WABA/Twilio/SMS number both
     * PERSIST across flapping, so counting them is stable. Official (WABA/Twilio/
     * SMS) numbers live in wa_provider_configs and are counted the same way, so a
     * mixed Unofficial+Official workspace is correctly treated as multi-number too.
     */
    private static function provisionedNumberCount(int $workspaceId): int
    {
        $baileys = \App\Models\Device::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['connected', 'disconnected']) // paired ≥ once; excludes never-paired needs_pair/failed ghosts
            ->count();

        $official = \App\Models\WaProviderConfig::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('provider', ['waba', 'twilio', 'sms'])
            ->whereIn('status', ['connected', 'disconnected'])
            ->count();

        return $baileys + $official;
    }

    /** Drop the cached multi-number answer (after a number connects/disconnects, or in tests). */
    public static function forgetMultiNumber(?int $workspaceId = null): void
    {
        if ($workspaceId === null) {
            self::$multiNumberCache = [];
        } else {
            unset(self::$multiNumberCache[$workspaceId]);
        }
    }

    /**
     * Find, or create with $attributes. Race-safe: the existence check is
     * re-run under a row lock inside the transaction, so a burst of concurrent
     * messages for a new number yields exactly one thread.
     *
     * @param  array<string, mixed>  $attributes  used only when creating
     */
    public static function findOrCreate(int $workspaceId, ?string $jidOrPhone, array $attributes = [], ?int $receivingDeviceId = null): ?Conversation
    {
        $digits = self::digitsFor($jidOrPhone);
        if ($workspaceId <= 0 || $digits === null) {
            return null;
        }

        // Fast path — no transaction for the overwhelmingly common case of an
        // existing thread.
        if ($found = self::find($workspaceId, $jidOrPhone, $receivingDeviceId)) {
            return $found;
        }

        return DB::transaction(function () use ($workspaceId, $jidOrPhone, $digits, $attributes, $receivingDeviceId) {
            if ($found = self::findForUpdate($workspaceId, $jidOrPhone, $receivingDeviceId)) {
                return $found;
            }

            return Conversation::create(array_merge([
                'workspace_id'    => $workspaceId,
                'raw_jid'         => (string) $jidOrPhone,
                // `title` is NOT NULL with no DB default — a caller that omits
                // it would fatal on insert. Fall back to the saved contact name
                // for this number, then the bare number, so the queue never
                // shows a blank row.
                'title'           => self::defaultTitle($workspaceId, $digits),
                'origin'          => 'inbox',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'last_message_at' => now(),
            ], $attributes, [
                // Always authoritative — never let a caller pass a stale value.
                'workspace_id'   => $workspaceId,
                'contact_digits' => $digits,
            ]));
        });
    }

    /**
     * A display label for a brand-new thread.
     *
     * `Contact.mobile` is encrypted at rest, so it cannot be matched with a SQL
     * WHERE — the rows have to be hydrated and compared in PHP. That is only
     * acceptable because this runs exclusively when a thread is being OPENED,
     * never on the per-message hot path.
     */
    private static function defaultTitle(int $workspaceId, string $digits): string
    {
        try {
            $name = \App\Models\Contact::nameForPhone($workspaceId, $digits);
            if ($name) {
                return $name . ' · +' . $digits;
            }
        } catch (\Throwable $e) {
            // Never let a contact-lookup problem block opening the thread.
        }

        return '+' . $digits;
    }

    /**
     * Point the thread at the channel that most recently carried a message, so
     * an operator reply goes back the way the customer came in.
     *
     * This is what makes rule 2 safe: threads are no longer split per engine,
     * so the thread itself has to remember which engine is current. Only moves
     * forward on real traffic — never on a passive read.
     */
    public static function stampChannel(Conversation $convo, ?string $provider, $deviceId = null): void
    {
        $changes = [];

        // COEXISTENCE — PREFER OFFICIAL. A number connected on BOTH the official
        // Cloud API (waba/twilio) AND Unofficial (baileys) receives on both, so
        // without this the thread's provider FLIPS on every message. The inbox's
        // engine filter (Conversation::scopeForCurrentEngine) then hides the whole
        // thread whenever it's flipped to an engine that isn't in the workspace's
        // enabled set — that's the "incoming not showing on coexistence" bug.
        // Rule: UPGRADE baileys→official freely, but NEVER downgrade an already-
        // official thread back to baileys — pin it (and its device_id) to the
        // official channel. Single-engine threads only ever see one provider, so
        // this is a pure no-op for them (existing behaviour unchanged).
        $rank = ['waba' => 3, 'twilio' => 2, 'sms' => 1, 'baileys' => 0];
        $keepOfficial = false;

        if ($provider && (string) $convo->provider !== (string) $provider) {
            $curR = $rank[(string) $convo->provider] ?? 0;
            $newR = $rank[(string) $provider] ?? 0;
            if ($convo->provider && $newR < $curR) {
                // Would downgrade official → Unofficial: keep the official channel.
                $keepOfficial = true;
            } else {
                $changes['provider'] = $provider;
            }
        }

        // device_id: skip the rebind entirely when we're pinning to the official
        // channel, so a Baileys inbound can't move a WABA thread onto the Baileys
        // device row (which would break official reply routing).
        if (!$keepOfficial && $deviceId && (int) $convo->device_id !== (int) $deviceId) {
            // In a MULTI-number workspace the thread is partitioned by its
            // receiving number (see resolveScoped), so moving device_id would
            // rebind it to a DIFFERENT number and break both the per-number
            // separation and reply routing. Only BIND when it's not set yet
            // (adopting an unbound thread); never FLIP an already-bound one.
            // Single-number workspaces keep the old "follow the last message"
            // behaviour so a reply still goes back over the channel last used.
            $canMove = empty($convo->device_id)
                || ! self::workspaceHasMultipleNumbers((int) $convo->workspace_id);
            if ($canMove) {
                $changes['device_id'] = (int) $deviceId;
            }
        }

        if ($changes) {
            $convo->forceFill($changes)->save();
        }
    }

    /**
     * Learn a JID shape we had not seen for this thread. Keeps `alt_jid`
     * carrying the OTHER identifier (typically the @lid twin of a phone), which
     * is what lets a LID-only inbound resolve to the same thread.
     */
    public static function rememberJid(Conversation $convo, ?string $jid): void
    {
        $jid = trim((string) $jid);
        if ($jid === '') {
            return;
        }

        $known = array_filter([(string) $convo->raw_jid, (string) $convo->alt_jid]);
        if (in_array($jid, $known, true)) {
            return;
        }

        // Prefer the phone form on raw_jid — outbound routing reads it.
        if ((string) $convo->raw_jid === '') {
            $convo->forceFill(['raw_jid' => $jid])->save();
        } elseif ((string) $convo->alt_jid === '') {
            $convo->forceFill(['alt_jid' => $jid])->save();
        }
    }

    /**
     * The match predicate. `contact_digits` is the indexed fast path; the
     * legacy string legs cover rows written before the column existed and are
     * harmless once every row is backfilled.
     *
     * Widget threads are excluded outright — they are keyed by visitor id, not
     * by a phone number, and must never be reachable by a numeric lookup.
     */
    private static function matchQuery(int $workspaceId, string $digits): Builder
    {
        $shapes = self::legacyShapes($digits);

        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('channel', Conversation::ENGINE_AGNOSTIC_CHANNELS)
            ->where(function (Builder $q) use ($digits, $shapes) {
                $q->where('contact_digits', $digits)
                  ->orWhereIn('raw_jid', $shapes)
                  ->orWhereIn('alt_jid', $shapes);
            });
    }
}
