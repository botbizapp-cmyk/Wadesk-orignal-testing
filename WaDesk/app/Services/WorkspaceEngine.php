<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "what send engine is this workspace on, and
 * which devices / configs count as valid senders right now?"
 *
 * Engines: waba | baileys | twilio
 *
 * Resolution order:
 *   1. Workspace's primary WaProviderConfig (set per-workspace at /devices)
 *   2. Platform default (system_settings.default_send_method)
 *   3. Hard fallback to 'baileys' (legacy behavior)
 *
 * Use this everywhere a send form, KPI card, or dashboard needs to filter
 * data to "only what's relevant for this workspace's current engine".
 * Without it, switching a workspace from Baileys → WABA leaves wrong-
 * engine devices visible in pickers (resulting in silent send failures)
 * and Baileys send counts inflating WABA dashboards.
 */
class WorkspaceEngine
{
    public const ENGINE_WABA    = 'waba';
    public const ENGINE_BAILEYS = 'baileys';
    public const ENGINE_TWILIO  = 'twilio';
    // Instagram DMs, served via the linked Instaflow install. NOT a WhatsApp send
    // engine, so it is never part of allowed_send_methods / enginesFor() /
    // availableFor() — those stay WhatsApp-only. It only surfaces in senders()
    // when a caller explicitly asks for it (passes 'instagram' in $engines), which
    // is gated on InstaflowClient::isConnected() at the call site.
    public const ENGINE_INSTAGRAM = 'instagram';
    // Facebook Page DMs (Messenger), served via the connected FacebookPage rows.
    // Like Instagram this is NOT a WhatsApp send engine — it never joins
    // allowed_send_methods / enginesFor() / availableFor(), and only surfaces in
    // senders() when a caller explicitly includes 'facebook' in $engines (gated on
    // facebook_enabled + FacebookPage::hasConnected inside the branch). Keyed
    // facebook:<FacebookPage row id> (the row id, NOT the Meta page_id) to match
    // the flow builder + FacebookIngestService::resolveFbKeywordFlow.
    public const ENGINE_FACEBOOK = 'facebook';

    // Telegram bot DMs, served via the connected TelegramBot rows. Like
    // Instagram/Facebook this is NOT a WhatsApp send engine — it never joins
    // allowed_send_methods / enginesFor() / availableFor(), and only surfaces in
    // senders() when a caller explicitly includes 'telegram' in $engines (gated
    // on telegram_enabled + TelegramBot::hasConnected inside the branch). Keyed
    // telegram:<TelegramBot row id> to match the flow builder + webhook resolver.
    public const ENGINE_TELEGRAM = 'telegram';

    // LINE (Messaging API) — engine-agnostic like Telegram. Keyed line:<row id>.
    public const ENGINE_LINE = 'line';
    public const ENGINE_WECHAT = 'wechat';
    public const ENGINE_VIBER = 'viber';

    // SMS (Twilio / MSG91). Like telegram/facebook it is a non-WhatsApp channel —
    // it is NOT auto-added to enginesFor()/availableFor() and only surfaces in
    // senders() when a caller explicitly includes 'sms' in $engines (gated on
    // sms_enabled + a connected provider='sms' row inside the branch). Keyed
    // sms:<WaProviderConfig row id>. Reuses the workspace's Twilio credentials.
    public const ENGINE_SMS = 'sms';

    // Email, served via the linked MailTrixy install (WorkspaceEmailAccount
    // mirror rows). Like the other side-channels it is NOT a WhatsApp send
    // engine — never part of allowed_send_methods / enginesFor() /
    // availableFor() — and only surfaces in senders() when a caller explicitly
    // includes 'email' in $engines (gated on email_enabled +
    // WorkspaceEmailAccount::hasConnected inside the branch). Keyed
    // email:<WorkspaceEmailAccount row id> to match the flow builder + the
    // ingest resolver (raw_jid 'email:<mirrorRowId>:<mtxConversationId>').
    public const ENGINE_EMAIL = 'email';

    private static array $engineCache = [];   // workspace_id => engine (single, default)
    private static array $deviceCache = [];   // workspace_id => Collection of valid device IDs (single engine)
    private static array $enginesCache = [];  // workspace_id => array of enabled engines (multi)

    /**
     * Active engine for the given workspace. Cached per-request so a
     * single page render doesn't hit the DB multiple times for the
     * same answer.
     */
    public static function for(?int $workspaceId): string
    {
        if (!$workspaceId) return self::platformDefault();
        if (isset(self::$engineCache[$workspaceId])) return self::$engineCache[$workspaceId];

        // Only providers the admin has actually enabled platform-wide
        // (allowed_send_methods) may be resolved as a workspace's active
        // engine. Without this gate, a STALE wa_provider_configs row —
        // e.g. an old/disconnected provider='twilio' row left over from a
        // trial, or a pending row never finished — would silently win the
        // fallback below and flip the whole workspace's engine to Twilio,
        // surfacing Twilio senders in pickers/badges/previews even though
        // the workspace really sends over the Unofficial API (Baileys) and
        // Twilio isn't enabled in admin. We constrain BOTH lookups to the
        // allowed set so a wrong-engine row can never leak through.
        $allowed = SystemSetting::get('allowed_send_methods', [self::ENGINE_BAILEYS]);
        $allowed = is_array($allowed) ? array_values(array_filter($allowed)) : [$allowed];
        if (empty($allowed)) $allowed = [self::ENGINE_BAILEYS];

        // provider=meta_ads rows hold Click-to-WhatsApp ad credentials,
        // NOT a messaging send engine. They must never be resolved as
        // the active engine (a workspace can run Meta Ads while sending
        // via Baileys/Twilio), so they're excluded from both lookups.
        $cfg = WaProviderConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', '!=', 'meta_ads')
            ->whereIn('provider', $allowed)
            ->where('is_primary', true)
            ->first(['provider']);

        // Fallback: most-recently-CONNECTED admin-enabled provider. The
        // connected-status filter keeps a half-set-up/disconnected row
        // (which can't actually send) from claiming the engine.
        $engine = $cfg?->provider
            ?? WaProviderConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider', '!=', 'meta_ads')
                ->whereIn('provider', $allowed)
                ->where('status', WaProviderConfig::STATUS_CONNECTED)
                ->orderByDesc('connected_at')
                ->value('provider')
            ?? self::platformDefault();

        return self::$engineCache[$workspaceId] = $engine;
    }

    public static function platformDefault(): string
    {
        try {
            return (string) (\App\Models\SystemSetting::get('default_send_method', self::ENGINE_BAILEYS) ?: self::ENGINE_BAILEYS);
        } catch (\Throwable $e) {
            return self::ENGINE_BAILEYS;
        }
    }

    /**
     * The user id whose legacy NULL-workspace devices may be included as an
     * orphan fallback in the device/sender queries below — or 0 to disable it.
     *
     * Returns 0 while a platform admin is IMPERSONATING a workspace: auth() stays
     * the admin (only current_workspace_id is swapped), so the fallback would
     * otherwise leak the ADMIN's own orphan devices (e.g. "Hivera") into the
     * client's sender pickers — even though the Devices page correctly hides them.
     * Mirrors Device::scopeForCurrentWorkspace's impersonation guard.
     */
    private static function orphanPairerUserId(): int
    {
        if (session('impersonation_session_id')) return 0;
        return (int) (auth()->id() ?? 0);
    }

    /**
     * May this workspace configure the given sender model? Mirrors exactly what
     * senders()/validDeviceIds() LIST, orphan fallback included.
     *
     * A controller that instead asserts `workspace_id === $wsId` rejects a card
     * the very same page just rendered: a legacy Device with workspace_id NULL
     * is listed via the fallback, but NULL never equals the id — so Unofficial
     * numbers 403'd on save while WABA/Twilio worked (their WaProviderConfig
     * rows always carry a workspace_id). Route ownership checks here so the
     * listing rule and the auth rule can never drift apart again.
     */
    public static function ownsSender(?object $model, ?int $workspaceId): bool
    {
        if (!$model || !$workspaceId) return false;

        $modelWs = $model->workspace_id === null ? null : (int) $model->workspace_id;
        if ($modelWs === (int) $workspaceId) return true;

        // Orphan fallback — legacy NULL-workspace devices, current pairer only.
        // Never for WaProviderConfig, and never while impersonating.
        if ($model instanceof Device && $modelWs === null) {
            $uid = self::orphanPairerUserId();
            return $uid > 0 && (int) $model->user_id === $uid;
        }
        return false;
    }

    public static function isWaba(?int $workspaceId): bool    { return self::for($workspaceId) === self::ENGINE_WABA; }
    public static function isBaileys(?int $workspaceId): bool { return self::for($workspaceId) === self::ENGINE_BAILEYS; }
    public static function isTwilio(?int $workspaceId): bool  { return self::for($workspaceId) === self::ENGINE_TWILIO; }

    /**
     * Human + machine descriptor for an engine string, for UI badges.
     * Returns [channel, label, code]:
     *   waba    → meta        / Meta            / W
     *   twilio  → twilio      / Twilio          / T
     *   baileys → unofficial  / Unofficial API  / U
     * (Anything unknown falls back to the Unofficial descriptor.)
     */
    public static function descriptor(?string $engine): array
    {
        return match ($engine) {
            self::ENGINE_WABA      => ['channel' => 'meta',       'label' => 'Meta',           'code' => 'W'],
            self::ENGINE_TWILIO    => ['channel' => 'twilio',     'label' => 'Twilio',         'code' => 'T'],
            self::ENGINE_INSTAGRAM => ['channel' => 'instagram',  'label' => 'Instagram',      'code' => 'I'],
            self::ENGINE_FACEBOOK  => ['channel' => 'facebook',   'label' => 'Facebook',       'code' => 'F'],
            self::ENGINE_TELEGRAM  => ['channel' => 'telegram',   'label' => 'Telegram',       'code' => 'T'],
            self::ENGINE_LINE      => ['channel' => 'line',       'label' => 'LINE',           'code' => 'L'],
            self::ENGINE_WECHAT    => ['channel' => 'wechat',     'label' => 'WeChat',         'code' => 'W'],
            self::ENGINE_VIBER     => ['channel' => 'viber',      'label' => 'Viber',          'code' => 'V'],
            self::ENGINE_SMS       => ['channel' => 'sms',        'label' => 'SMS',            'code' => 'S'],
            self::ENGINE_EMAIL     => ['channel' => 'email',      'label' => 'Email',          'code' => 'E'],
            default                => ['channel' => 'unofficial', 'label' => 'Unofficial API', 'code' => 'U'],
        };
    }

    // =================================================================
    // Multi-engine API (Phase 1) — a workspace may run ANY SUBSET of the
    // platform-allowed engines at once. These are ADDITIVE: for()/isWaba/
    // validDeviceIds() keep their single-engine meaning so nothing changes
    // until later phases adopt these. `for()` == the DEFAULT engine.
    // =================================================================

    /** Platform-wide enabled engine set (allowed_send_methods), normalised + non-empty. */
    private static function allowedMethods(): array
    {
        $allowed = SystemSetting::get('allowed_send_methods', [self::ENGINE_BAILEYS]);
        $allowed = is_array($allowed) ? array_values(array_filter($allowed)) : [$allowed];
        return empty($allowed) ? [self::ENGINE_BAILEYS] : $allowed;
    }

    /** True when a specific engine ('waba'|'baileys'|'twilio') is admin-enabled. */
    public static function isEngineAllowed(string $engine): bool
    {
        return in_array(strtolower($engine), self::allowedMethods(), true);
    }

    /**
     * Human labels for the ADMIN-ENABLED engines only — for "Add X, Y, or Z"
     * connect UI. A disabled engine (Twilio / Unofficial API removed from
     * allowed_send_methods) is omitted so it never appears anywhere.
     */
    public static function allowedEngineLabels(): array
    {
        $map = [
            self::ENGINE_WABA    => 'WABA',
            self::ENGINE_BAILEYS => 'Unofficial API',
            self::ENGINE_TWILIO  => 'Twilio',
        ];
        $out = [];
        foreach (self::allowedMethods() as $m) {
            if (isset($map[$m])) $out[] = $map[$m];
        }
        return $out ?: ['WABA'];
    }

    /** "WABA, Unofficial API, or Twilio" — admin-enabled engines only, for UI copy. */
    public static function allowedEnginesSentence(): string
    {
        $labels = self::allowedEngineLabels();
        if (count($labels) === 1) return $labels[0];
        $last = array_pop($labels);
        return implode(', ', $labels) . ' or ' . $last;
    }

    /**
     * The engine used for sends that DON'T pin a specific sender (automated /
     * commerce / AI fallback). Today this is exactly the single-engine answer
     * from for() (primary config → most-recent connected → platform default),
     * so behaviour is unchanged. Later phases may prefer workspaces.default_engine.
     */
    public static function defaultEngineFor(?int $workspaceId): string
    {
        return self::for($workspaceId);
    }

    /**
     * All engines this workspace can send through right now — the intersection
     * of: platform allowed_send_methods, the workspace's connected senders, and
     * (when set) the per-workspace enabled_engines subset. Default engine first.
     * Never empty (falls back to the default engine) so callers always have one.
     */
    public static function enginesFor(?int $workspaceId): array
    {
        if (!$workspaceId) return [self::platformDefault()];
        if (isset(self::$enginesCache[$workspaceId])) return self::$enginesCache[$workspaceId];

        $allowed = self::allowedMethods();

        // Per-workspace subset (enabled_engines JSON, provider keys). NULL/empty
        // = no extra restriction beyond what's allowed + connected.
        $subset = null;
        try {
            $raw = Workspace::query()->find($workspaceId)?->enabled_engines; // array|null (cast)
            if (is_array($raw)) {
                $raw = array_values(array_filter($raw));
                if (!empty($raw)) $subset = $raw;
            }
        } catch (\Throwable $e) { /* column missing / pre-migration → no restriction */ }

        // Connected provider rows (meta_ads is an ad-credential row, never an engine).
        $connected = WaProviderConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', '!=', 'meta_ads')
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->pluck('provider')->map(fn ($p) => (string) $p)->unique()->values()->all();

        // Baileys also counts as connected when the workspace OWNS a Baileys
        // device, even without a pointer config row. A NULL-workspace (personal /
        // orphan) device only enables baileys for a genuinely LEGACY install —
        // one with no connected provider configs at all. Previously the
        // unconditional `orWhereNull` leg let a stray orphan device from another
        // context mark baileys "connected" here, which surfaced a phantom device
        // on the dashboard that /devices didn't list (#14).
        if (!in_array(self::ENGINE_BAILEYS, $connected, true)) {
            $ownsDevice = Device::query()->where('workspace_id', $workspaceId)->exists();
            $legacyOrphan = empty($connected)
                && Device::query()->whereNull('workspace_id')->exists();
            if ($ownsDevice || $legacyOrphan) $connected[] = self::ENGINE_BAILEYS;
        }

        $engines = array_values(array_filter($connected, function ($p) use ($allowed, $subset) {
            return in_array($p, $allowed, true) && ($subset === null || in_array($p, $subset, true));
        }));

        if (empty($engines)) $engines = [self::defaultEngineFor($workspaceId)];

        // Default engine first, deduped.
        $default = self::defaultEngineFor($workspaceId);
        $engines = array_values(array_unique(array_merge(
            in_array($default, $engines, true) ? [$default] : [],
            $engines
        )));

        return self::$enginesCache[$workspaceId] = $engines;
    }

    /**
     * Engines this workspace is ALLOWED to use — the platform allowed_send_methods
     * intersected with the workspace's enabled_engines subset (when set), WHETHER
     * OR NOT each is connected yet. Default-first, never empty.
     *
     * This is the set the /devices page renders a connect section for: enginesFor()
     * only lists ALREADY-CONNECTED engines, which can't bootstrap a first
     * connection (you could never see the "Add WABA" / "Connect Twilio" panel for
     * an engine you just enabled). Send pickers keep using enginesFor() (connected
     * only) because you can only send from a number that's actually connected.
     */
    /**
     * Every engine this platform knows about — the WhatsApp family AND the
     * standalone channels. Deliberately UNGATED: it is the full vocabulary, not
     * a permission answer. Pass it to senders() when a caller wants "all the
     * accounts this workspace can send from" across every channel; each branch
     * in senders() applies its own platform toggle + hasConnected() gate, so
     * nothing leaks from a disabled or unconnected channel.
     *
     * Use availableFor() instead when you need the workspace's PERMITTED
     * WhatsApp engines (plan + admin subset) — e.g. which connect panels to
     * render on /devices.
     */
    public static function allEngines(): array
    {
        return [
            self::ENGINE_BAILEYS,
            self::ENGINE_WABA,
            self::ENGINE_TWILIO,
            self::ENGINE_INSTAGRAM,
            self::ENGINE_FACEBOOK,
            self::ENGINE_TELEGRAM,
            self::ENGINE_LINE,
            self::ENGINE_WECHAT,
            self::ENGINE_VIBER,
            self::ENGINE_SMS,
            self::ENGINE_EMAIL,
        ];
    }

    public static function availableFor(?int $workspaceId): array
    {
        $allowed = self::allowedMethods();

        // Per-workspace subset (enabled_engines JSON). NULL/empty = no extra
        // restriction beyond the platform allowed set.
        $subset = null;
        if ($workspaceId) {
            try {
                $raw = Workspace::query()->find($workspaceId)?->enabled_engines;
                if (is_array($raw)) {
                    $raw = array_values(array_filter($raw));
                    if (!empty($raw)) $subset = $raw;
                }
            } catch (\Throwable $e) { /* column missing / pre-migration → no restriction */ }
        }

        $avail = array_values(array_filter(
            $allowed,
            fn ($p) => $p !== 'meta_ads' && ($subset === null || in_array($p, $subset, true))
        ));
        if (empty($avail)) $avail = [self::defaultEngineFor($workspaceId)];

        // Default engine first, deduped.
        $default = self::defaultEngineFor($workspaceId);
        return array_values(array_unique(array_merge(
            in_array($default, $avail, true) ? [$default] : [],
            $avail
        )));
    }

    /** Is this engine currently usable (connected + allowed) for the workspace? */
    public static function isEngineEnabled(?int $workspaceId, string $engine): bool
    {
        return in_array($engine, self::enginesFor($workspaceId), true);
    }

    /** Valid sender IDs for ONE engine (Device ids for baileys; WaProviderConfig ids for waba/twilio). */
    public static function validDeviceIdsForEngine(?int $workspaceId, string $engine): Collection
    {
        if (!$workspaceId) return collect();
        return match ($engine) {
            self::ENGINE_BAILEYS => Device::query()
                // Match senders()/scopeForCurrentWorkspace: this workspace's devices
                // plus legacy NULL-workspace devices ONLY for the current pairer, so
                // server-side send validation can't be tricked into accepting an
                // orphan / other-tenant device id the picker no longer offers.
                ->where(function ($q) use ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                    $uid = self::orphanPairerUserId();
                    if ($uid) $q->orWhere(fn ($qq) => $qq->whereNull('workspace_id')->where('user_id', $uid));
                })
                ->pluck('id'),
            self::ENGINE_WABA, self::ENGINE_TWILIO => WaProviderConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider', $engine)
                ->where('status', WaProviderConfig::STATUS_CONNECTED)
                ->pluck('id'),
            default => collect(),
        };
    }

    /**
     * Every connected sender across ALL enabled engines, for the unified
     * compose picker. Each entry:
     *   key (engine:id), engine, id, phone, label, descriptor, is_default
     * The composite `key` disambiguates the overlapping devices.id /
     * wa_provider_configs.id namespaces. Default-engine senders sort first.
     */
    public static function senders(?int $workspaceId, ?array $engines = null, bool $dedupePreferWaba = false): Collection
    {
        if (!$workspaceId) return collect();
        $default = self::defaultEngineFor($workspaceId);
        $out = collect();

        // Compose pickers pass null → only the workspace's allowed/active
        // engines (enginesFor). The /devices hub passes its own engine set so
        // it can also list channels the operator CONNECTED even if the engine
        // isn't in the platform's allowed-send set (display + manage only).
        $engineSet = $engines !== null
            ? array_values(array_unique(array_filter($engines)))
            : self::enginesFor($workspaceId);

        foreach ($engineSet as $engine) {
            $desc = self::descriptor($engine);
            if ($engine === self::ENGINE_INSTAGRAM) {
                // Instagram accounts linked from Instaflow (WorkspaceIgAccount
                // mirrors). Keyed instagram:<instaflow_account_id> — the id here is
                // the Instaflow account id (string), not a local table id, so it
                // never collides with device / provider config ids. Only reached
                // when a caller explicitly includes 'instagram' in $engines.
                // Gate on the LIVE bridge: when InstaMagic is disconnected the
                // mirror rows persist, so without this the picker would still list
                // stale Instagram senders (auto-reply, campaigns, chat).
                if (!\App\Models\WorkspaceIgAccount::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\WorkspaceIgAccount::query()
                    ->where('workspace_id', $workspaceId)
                    ->connected()
                    ->get(['instaflow_account_id', 'username', 'name'])
                    ->each(function ($a) use (&$out, $engine, $desc, $default) {
                        $handle = '@' . ltrim((string) ($a->username ?: $a->name), '@');
                        $out->push([
                            'key'        => $engine . ':' . $a->instaflow_account_id,
                            'engine'     => $engine,
                            'id'         => (string) $a->instaflow_account_id,
                            'phone'      => '',
                            'label'      => $a->name ?: $handle,
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_FACEBOOK) {
                // Connected Facebook Pages (Messenger senders). Keyed
                // facebook:<FacebookPage row id> — the LOCAL row id, not the Meta
                // page_id, so the key never collides with device / provider config
                // ids and matches what the flow builder + FacebookIngestService
                // resolve on. Only reached when a caller explicitly includes
                // 'facebook' in $engines. Gated on the SAME "no dead channel" rule
                // every Facebook surface uses: the platform toggle
                // (facebook_enabled) AND at least one connected Page (mirrors the
                // Instagram hasConnected() gate above).
                if (!(bool) SystemSetting::get('facebook_enabled', false)
                    || !\App\Models\FacebookPage::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\FacebookPage::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'connected')
                    ->get(['id', 'name', 'username'])
                    ->each(function ($p) use (&$out, $engine, $desc) {
                        $handle = $p->username ? '@' . ltrim((string) $p->username, '@') : '';
                        $out->push([
                            'key'        => $engine . ':' . $p->id,
                            'engine'     => $engine,
                            'id'         => (int) $p->id,
                            'phone'      => $handle,
                            'label'      => $p->name ?: ($handle ?: $desc['label'] . ' · #' . $p->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_TELEGRAM) {
                // Connected Telegram bots. Keyed telegram:<TelegramBot row id> —
                // the LOCAL row id, matching the flow builder + webhook resolver
                // (raw_jid 'tg:<botRowId>:<chatId>'). Only reached when a caller
                // explicitly includes 'telegram' in $engines. Gated on the same
                // "no dead channel" rule every Telegram surface uses: the platform
                // toggle (telegram_enabled) AND at least one connected bot.
                if (!(bool) SystemSetting::get('telegram_enabled', false)
                    || !\App\Models\TelegramBot::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\TelegramBot::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('active', true)
                    ->get(['id', 'bot_name', 'bot_username'])
                    ->each(function ($b) use (&$out, $engine, $desc) {
                        $handle = $b->bot_username ? '@' . ltrim((string) $b->bot_username, '@') : '';
                        $out->push([
                            'key'        => $engine . ':' . $b->id,
                            'engine'     => $engine,
                            'id'         => (int) $b->id,
                            'phone'      => $handle,
                            'label'      => $b->bot_name ?: ($handle ?: $desc['label'] . ' · #' . $b->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_LINE) {
                // Connected LINE channels. Keyed line:<LineChannel row id> — matches
                // the webhook resolver (raw_jid 'line:<rowId>:<userId>'). Gated on the
                // platform toggle (line_enabled) AND at least one connected channel.
                if (!(bool) SystemSetting::get('line_enabled', false)
                    || !\App\Models\LineChannel::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\LineChannel::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('active', true)
                    ->get(['id', 'display_name', 'basic_id'])
                    ->each(function ($c) use (&$out, $engine, $desc) {
                        $handle = $c->basic_id ? (string) $c->basic_id : '';
                        $out->push([
                            'key'        => $engine . ':' . $c->id,
                            'engine'     => $engine,
                            'id'         => (int) $c->id,
                            'phone'      => $handle,
                            'label'      => $c->display_name ?: ($handle ?: $desc['label'] . ' · #' . $c->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_WECHAT) {
                // Connected WeChat Official Accounts. Keyed wechat:<WeChatChannel row
                // id> — matches the webhook resolver (raw_jid 'wechat:<rowId>:<openid>').
                // Gated on the platform toggle (wechat_enabled) AND a connected channel.
                if (!(bool) SystemSetting::get('wechat_enabled', false)
                    || !\App\Models\WeChatChannel::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\WeChatChannel::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('active', true)
                    ->get(['id', 'account_name', 'wx_id'])
                    ->each(function ($c) use (&$out, $engine, $desc) {
                        $handle = $c->wx_id ? (string) $c->wx_id : '';
                        $out->push([
                            'key'        => $engine . ':' . $c->id,
                            'engine'     => $engine,
                            'id'         => (int) $c->id,
                            'phone'      => $handle,
                            'label'      => $c->account_name ?: ($handle ?: $desc['label'] . ' · #' . $c->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_VIBER) {
                // Connected Viber Public Accounts. Keyed viber:<ViberChannel row id>
                // — matches the webhook resolver (raw_jid 'viber:<rowId>:<userId>').
                // Gated on the platform toggle (viber_enabled) AND a connected channel.
                if (!(bool) SystemSetting::get('viber_enabled', false)
                    || !\App\Models\ViberChannel::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\ViberChannel::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('active', true)
                    ->get(['id', 'bot_name', 'bot_uri'])
                    ->each(function ($c) use (&$out, $engine, $desc) {
                        $handle = $c->bot_uri ? (string) $c->bot_uri : '';
                        $out->push([
                            'key'        => $engine . ':' . $c->id,
                            'engine'     => $engine,
                            'id'         => (int) $c->id,
                            'phone'      => $handle,
                            'label'      => $c->bot_name ?: ($handle ?: $desc['label'] . ' · #' . $c->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_EMAIL) {
                // Linked email mailboxes (WorkspaceEmailAccount mirror rows —
                // the real account lives on the connected MailTrixy install).
                // Keyed email:<mirror row id> — matches the flow builder + the
                // ingest resolver (raw_jid 'email:<mirrorRowId>:<mtxConvId>').
                // Only reached when a caller explicitly includes 'email' in
                // $engines. Gated on the platform toggle (email_enabled), the
                // PLAN flag (access_email — the same crown LINE/Viber/WeChat
                // carry) AND hasConnected, which also checks the LIVE bridge, so
                // stale mirror rows never surface after the admin disconnects it.
                if (!(bool) SystemSetting::get('email_enabled', false)
                    || !PlanLimitGuard::hasFeature(Workspace::find($workspaceId), 'access_email')
                    || !\App\Models\WorkspaceEmailAccount::hasConnected($workspaceId)) {
                    continue;
                }
                \App\Models\WorkspaceEmailAccount::query()
                    ->forWorkspace($workspaceId)
                    ->connected()
                    ->get(['id', 'email', 'name'])
                    ->each(function ($a) use (&$out, $engine, $desc) {
                        $address = (string) $a->email;
                        $out->push([
                            'key'        => $engine . ':' . $a->id,
                            'engine'     => $engine,
                            'id'         => (int) $a->id,
                            'phone'      => $address,
                            'label'      => $a->name ?: ($address ?: $desc['label'] . ' · #' . $a->id),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_SMS) {
                // Connected SMS numbers (Twilio / MSG91) — WaProviderConfig
                // provider='sms'. Keyed sms:<config id>, matching the inbound
                // resolver + dispatchSms. Only reached when a caller explicitly
                // includes 'sms' in $engines. Gated on the platform toggle
                // (sms_enabled) — SMS is never auto-added to enginesFor, so it
                // can't leak into a WhatsApp picker that didn't ask for it.
                if (!(bool) SystemSetting::get('sms_enabled', false)) {
                    continue;
                }
                WaProviderConfig::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('provider', 'sms')
                    ->where('status', WaProviderConfig::STATUS_CONNECTED)
                    ->get(['id', 'display_label', 'phone_number'])
                    ->each(function ($c) use (&$out, $engine, $desc) {
                        $phone = preg_replace('/\D+/', '', (string) $c->phone_number);
                        $out->push([
                            'key'        => $engine . ':' . $c->id,
                            'engine'     => $engine,
                            'id'         => (int) $c->id,
                            'phone'      => $phone,
                            'label'      => $c->display_label ?: ($desc['label'] . ' · ' . $phone),
                            'descriptor' => $desc,
                            'is_default' => false,
                        ]);
                    });
            } elseif ($engine === self::ENGINE_BAILEYS) {
                // Scope EXACTLY like Device::scopeForCurrentWorkspace (what the
                // /devices hub uses): this workspace's devices, PLUS legacy
                // NULL-workspace devices ONLY when the current user paired them.
                // The old `orWhereNull('workspace_id')` had NO user guard, so an
                // orphan / other-tenant NULL-workspace phone leaked into every
                // workspace's compose picker even though the Devices page (correctly)
                // never listed it — the "why two devices?" report.
                $authUserId = self::orphanPairerUserId();
                Device::query()
                    ->where(function ($q) use ($workspaceId, $authUserId) {
                        $q->where('workspace_id', $workspaceId);
                        if ($authUserId) {
                            $q->orWhere(fn ($qq) => $qq->whereNull('workspace_id')->where('user_id', $authUserId));
                        }
                    })
                    // Connected senders only — match the WABA/Twilio STATUS_CONNECTED
                    // gate below (and every pre-multi-engine picker, which filtered
                    // status='connected'). A disconnected phone can't actually send,
                    // so it must never appear in a compose picker.
                    ->where('status', 'connected')
                    ->get(['id', 'device_name', 'country_code', 'phone_number'])
                    ->each(function ($d) use (&$out, $engine, $desc, $default) {
                        $phone = preg_replace('/\D+/', '', (string) ($d->country_code . $d->phone_number));
                        $out->push([
                            'key'        => $engine . ':' . $d->id,
                            'engine'     => $engine,
                            'id'         => (int) $d->id,
                            'phone'      => $phone,
                            'label'      => $d->device_name ?: ($desc['label'] . ' · ' . $phone),
                            'descriptor' => $desc,
                            'is_default' => $engine === $default,
                        ]);
                    });
            } else {
                WaProviderConfig::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('provider', $engine)
                    ->where('status', WaProviderConfig::STATUS_CONNECTED)
                    ->get(['id', 'display_label', 'phone_number'])
                    ->each(function ($c) use (&$out, $engine, $desc, $default) {
                        $phone = preg_replace('/\D+/', '', (string) $c->phone_number);
                        $out->push([
                            'key'        => $engine . ':' . $c->id,
                            'engine'     => $engine,
                            'id'         => (int) $c->id,
                            'phone'      => $phone,
                            'label'      => $c->display_label ?: ($desc['label'] . ' · ' . $phone),
                            'descriptor' => $desc,
                            'is_default' => $engine === $default,
                        ]);
                    });
            }
        }

        // COEXISTENCE dedup (campaigns): the SAME physical WhatsApp number can be
        // connected as BOTH a WABA (official) sender AND a Baileys (Unofficial)
        // device at once. Left un-collapsed, an operator can pick — or the system
        // can resolve — two senders for one number, and the recipient gets the
        // campaign TWICE. When asked, collapse same-phone senders to ONE, keeping
        // the WABA/official one so a coexistence number sends exactly once via the
        // official channel. Non-phone channels (instagram/facebook/telegram) and
        // blank phones carry a unique key and are never collapsed.
        if ($dedupePreferWaba) {
            $rank = [self::ENGINE_WABA => 0, self::ENGINE_TWILIO => 1, self::ENGINE_SMS => 2, self::ENGINE_BAILEYS => 3];
            $out = $out
                ->sortBy(fn ($s) => $rank[$s['engine']] ?? 9)             // official first
                ->unique(fn ($s) => ($s['phone'] ?? '') !== '' ? 'ph:' . $s['phone'] : $s['key'])
                ->values();
        }

        // Default-engine senders first, then the rest in engine order.
        return $out->sortByDesc(fn ($s) => $s['is_default'] ? 1 : 0)->values();
    }

    /**
     * Parse a unified picker value into ['engine','id'].
     *
     * The Phase 3 sender pickers submit a composite `engine:id` key (because
     * devices.id and wa_provider_configs.id overlap). This splits + validates
     * it. For BACK-COMPAT a bare integer id (the legacy `device_id` contract)
     * is still accepted and its engine inferred from the workspace default, so
     * an un-migrated form or stale submission keeps working.
     *
     * Returns null for empty/garbage input.
     */
    public static function parseSenderKey(?int $workspaceId, ?string $key): ?array
    {
        $key = trim((string) $key);
        if ($key === '') return null;

        if (str_contains($key, ':')) {
            [$engine, $id] = explode(':', $key, 2);
            $engine = strtolower(trim($engine));
            $id = (int) trim($id);
            if ($id <= 0) return null;
            if (!in_array($engine, [self::ENGINE_BAILEYS, self::ENGINE_WABA, self::ENGINE_TWILIO, self::ENGINE_INSTAGRAM, self::ENGINE_FACEBOOK, self::ENGINE_TELEGRAM, self::ENGINE_LINE, self::ENGINE_WECHAT, self::ENGINE_VIBER, self::ENGINE_SMS, self::ENGINE_EMAIL], true)) return null;
            return ['engine' => $engine, 'id' => $id];
        }

        // Legacy bare id → infer the engine from the workspace default.
        $id = (int) $key;
        if ($id <= 0) return null;
        return ['engine' => self::defaultEngineFor($workspaceId), 'id' => $id];
    }

    /**
     * Resolve a submitted picker key to the actual sender row it names,
     * validated against senders() (so a forged/stale key for a sender the
     * workspace can't use returns null). Returns the sender array
     * (key/engine/id/phone/label/descriptor/is_default) or null.
     */
    public static function senderForKey(?int $workspaceId, ?string $key): ?array
    {
        $parsed = self::parseSenderKey($workspaceId, $key);
        if (!$parsed) return null;
        // Resolve against a set that ALWAYS includes the key's OWN engine. Some
        // engines (Instagram) are deliberately omitted from the default senders()
        // set so they don't clutter WhatsApp-only pickers — validating an IG key
        // against that default set would wrongly drop a legitimately-picked IG
        // sender on save. Adding the parsed engine here makes the key resolvable
        // without widening any other picker's displayed options.
        $engines = array_values(array_unique(array_merge(
            self::enginesFor($workspaceId),
            [$parsed['engine']]
        )));
        return self::senders($workspaceId, $engines)
            ->firstWhere('key', $parsed['engine'] . ':' . $parsed['id']);
    }

    /**
     * Valid sender IDs for the workspace's current engine. Used to
     * filter device pickers in compose forms so operators can only
     * select something the dispatcher will actually route through.
     *
     * Returns a Collection of integer IDs. The picker queries Device
     * (Baileys) or WaProviderConfig (WABA / Twilio) and intersects
     * its result with this list.
     */
    public static function validDeviceIds(?int $workspaceId): Collection
    {
        if (!$workspaceId) return collect();
        if (isset(self::$deviceCache[$workspaceId])) return self::$deviceCache[$workspaceId];

        $engine = self::for($workspaceId);
        $ids = match ($engine) {
            self::ENGINE_BAILEYS => Device::query()
                // Legacy NULL-workspace rows only for their pairer (match
                // scopeForCurrentWorkspace) — never accept another tenant's orphan.
                ->where(function ($q) use ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                    $uid = self::orphanPairerUserId();
                    if ($uid) $q->orWhere(fn ($qq) => $qq->whereNull('workspace_id')->where('user_id', $uid));
                })
                ->pluck('id'),
            self::ENGINE_WABA, self::ENGINE_TWILIO => WaProviderConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider', $engine)
                ->pluck('id'),
            default => collect(),
        };

        return self::$deviceCache[$workspaceId] = collect($ids);
    }

    /**
     * Reset the per-request cache. Call from tests or when the
     * workspace's primary provider config has just changed mid-request.
     */
    public static function flush(): void
    {
        self::$engineCache = [];
        self::$deviceCache = [];
        self::$enginesCache = [];
    }

    /**
     * Build a Device::query() scoped to senders the active engine can
     * actually use. For Baileys workspaces this is the devices table.
     * For WABA / Twilio it's the WaProviderConfig rows surfaced as
     * pseudo-devices via the controller's resolver. Returns null when
     * the engine doesn't use the legacy devices table — caller should
     * resolve WaProviderConfig directly.
     */
    public static function senderDeviceQuery(?int $workspaceId): ?\Illuminate\Database\Eloquent\Builder
    {
        if (!self::isBaileys($workspaceId)) return null;
        return Device::query()->where(function ($q) use ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
            // Legacy NULL-workspace devices only for their pairer (match
            // scopeForCurrentWorkspace) — no cross-tenant orphan leak, and 0
            // while impersonating so the admin's orphan devices don't leak in.
            $uid = self::orphanPairerUserId();
            if ($uid) $q->orWhere(fn ($qq) => $qq->whereNull('workspace_id')->where('user_id', $uid));
        });
    }
}
