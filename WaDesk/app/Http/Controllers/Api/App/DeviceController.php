<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mobile-app devices (B2). Lists the current workspace's connected WhatsApp
 * numbers, reports a single device's connection status, and returns the
 * contacts synced for a device.
 *
 * Response shapes are kept byte-compatible with the existing app:
 *   getDevices         → {devices: [{phone_number, device_name, active, ...}]}
 *   getConnectionStatus→ {success, status, progress, phone_number}
 *   getDeviceContacts  → {phoneNumber, contacts: [{name, number, pushname, isMyContact, avatar}]}
 *
 * Implementation runs against OUR current models (App\Models\Device,
 * App\Models\Contact) — not the old `device_user` schema. Every query is
 * scoped to the authed user's workspace via Device::forCurrentWorkspace()
 * / Contact::forCurrentWorkspace() so the app only ever sees its own data.
 */
class DeviceController extends Controller
{
    /**
     * GET /get-devices — list the workspace's WhatsApp devices.
     *
     * Old contract (WhatsAppMessageApiController::getDevices) returned
     * {devices: [{phone_number, device_name, active, ...raw row}]}. We map
     * our encrypted-at-rest Device rows into that same shape and add the
     * extra columns the new app screens read (country_code, region, status,
     * last_seen_at, assigned_user_id).
     *
     * MULTI-ENGINE: the `devices` table only holds Unofficial-API numbers.
     * A WABA (Meta Cloud API) or Twilio number is a `wa_provider_configs`
     * row, so listing devices alone made a connected Meta/Twilio number
     * invisible in the app — even though the app can connect one itself via
     * POST /devices/connect-waba. We append those connected channels the
     * same way /devices does on the web (WorkspaceEngine::senders minus
     * baileys, which the Device query already covers). Every row now also
     * carries `engine` + `sender_key` so the app can tell the two stores
     * apart: provider-config ids and device ids are from different tables
     * and WILL collide, so actions must key off `sender_key`, not `id`.
     */
    public function getDevices(Request $request): JsonResponse
    {
        try {
            $devices = Device::query()
                ->forCurrentWorkspace()
                ->orderByDesc('active')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Device $d) => $this->devicePayload($d))
                ->values();

            $devices = $devices->concat($this->connectedChannelPayloads(
                (int) ($request->user()->current_workspace_id ?? 0)
            ))->values();

            return response()->json(['devices' => $devices], 200);
        } catch (\Throwable $e) {
            Log::error('WaDesk app getDevices failed: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
            ]);

            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }

    /**
     * GET /device-status/{deviceId} — one device's connection status.
     *
     * Old contract (WhatsAppController::getConnectionStatus) returned
     * {success, status, progress, phone_number}. {deviceId} in the old
     * project was the bare phone number; the new app addresses devices by
     * their row id, so we resolve by id first and fall back to a phone-
     * number match for backward compatibility. Per the batch spec we also
     * surface last_seen_at + active.
     */
    public function deviceStatus(Request $request, $deviceId): JsonResponse
    {
        try {
            $device = $this->resolveDevice($deviceId);
            if (! $device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Device not found',
                ], 404);
            }

            return response()->json([
                'success'      => true,
                'status'       => $device->status,
                'progress'     => $this->progressFor($device),
                'phone_number' => $this->fullPhone($device),
                'active'       => (bool) $device->active,
                'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('WaDesk app deviceStatus failed: ' . $e->getMessage(), [
                'user_id'   => $request->user()?->id,
                'device_id' => $deviceId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get status',
            ], 500);
        }
    }

    /**
     * GET /device-contacts/{id} — contacts synced for this device.
     *
     * Old contract (WhatsAppController::getDeviceContacts) proxied to the
     * Node bridge (GET /api/get-contacts/{phone}) and reshaped the result
     * to {phoneNumber, contacts: [{name, number, pushname, isMyContact,
     * avatar}]}. We keep that exact shape: try the live bridge first (so a
     * freshly-paired device returns its real address book), then fall back
     * to the workspace's stored Contact rows — our Contact model is
     * workspace-scoped, not device-scoped, so the fallback returns the
     * workspace's contacts.
     */
    public function deviceContacts(Request $request, $id = null): JsonResponse
    {
        try {
            // Device resolution — the app hits the NO-ID form
            // (GET /device-contacts) and identifies the device via the
            // X-Device-Id header (validated + stashed as app_device_id by the
            // middleware). The legacy /device-contacts/{id} path still works and
            // wins when supplied.
            $deviceId = (int) ($id
                ?: $request->attributes->get('app_device_id', 0)
                ?: $request->input('device_id', 0));

            // Device is OPTIONAL. Contacts are a WORKSPACE-level book, not a
            // per-device one — the fallback below returns the same rows however
            // this resolves. Requiring a device meant a WABA/Twilio selection
            // (which is a wa_provider_configs row, NOT a devices row, so it can
            // never resolve here) got a hard 404 instead of the contacts it was
            // entitled to. The device now only decides whether we can ask the
            // Unofficial-API bridge for a live address book first.
            $device = $deviceId > 0 ? $this->resolveDevice($deviceId) : null;

            $phone = $device ? $this->fullPhone($device) : $this->selectedAccountPhone($request);

            // 1) Live address book from the Node bridge — but ONLY if it
            //    actually returned contacts. WhatsApp multi-device does NOT
            //    sync the phone address book into the Unofficial-API store, so
            //    the bridge usually returns an EMPTY list. In that case fall
            //    through to the workspace's stored contacts rather than handing
            //    the app an empty array.
            // Only a real Unofficial-API device has a bridge session to ask. A
            // WABA/Twilio number has no address book on the bridge, so skip
            // straight to the workspace book rather than burning a timeout.
            if ($device) {
                $live = $this->fetchLiveContacts($phone);
                if ($live !== null && ! empty($live['contacts'])) {
                    return response()->json($live, 200);
                }
            }

            // 2) Fall back to the workspace's stored contacts.
            $contacts = Contact::query()
                ->forCurrentWorkspace()
                ->orderByDesc('id')
                ->get()
                ->map(function (Contact $c) use ($phone) {
                    $number = preg_replace('/\D+/', '', (string) $c->mobile) ?: '';

                    return [
                        'name'        => (string) ($c->name ?: trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))),
                        'number'      => $number,
                        'pushname'    => (string) ($c->name ?? ''),
                        'isMyContact' => ($number !== '' && $number === $phone),
                        'avatar'      => $c->image
                            ? (\Illuminate\Support\Str::startsWith($c->image, ['http://', 'https://']) ? $c->image : media_url($c->image))
                            : null,
                    ];
                })
                ->values();

            return response()->json([
                'phoneNumber' => $phone,
                'contacts'    => $contacts,
                'source'      => 'workspace',
                // Echo what the selection actually resolved to, so the app can
                // tell "no number pinned" from "pinned to a WABA account"
                // without inferring it from a 404 that no longer happens.
                'device_id'   => $device?->id,
                'account_id'  => $request->attributes->get('app_provider_config_id'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('WaDesk app deviceContacts failed: ' . $e->getMessage(), [
                'user_id'   => $request->user()?->id,
                'device_id' => $id,
            ]);

            return response()->json([
                'status'  => 500,
                'error'   => 'Failed to fetch contacts',
                'message' => 'Something went wrong.',
            ], 500);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // POST /devices — add a new Baileys device (creates row, starts the
    // Node session, returns the QR data URL + a status URL the app can
    // poll). Mirrors the web /devices store flow.
    // -----------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_name'             => 'required|string|min:2|max:191',
            'country_code'            => 'required|string|max:8',
            'phone_number'            => 'required|string|min:5|max:32',
            'region'                  => 'nullable|string|max:16',
            'activate_after_pairing'  => 'nullable|boolean',
        ]);

        $user = $request->user();
        $wsId = (int) ($user->current_workspace_id ?? 0);

        // Plan cap — same workspace-scoped UNIFIED count the web uses
        // (Baileys devices + WABA/Twilio configs). Throws when the
        // workspace is at its plan limit.
        try {
            \App\Services\PlanLimitGuard::check(
                $user->currentWorkspace,
                'device_limit',
                Device::query()->forCurrentWorkspace()->count()
                    + \App\Models\WaProviderConfig::query()
                        ->where('workspace_id', $wsId)
                        ->whereIn('provider', ['waba', 'twilio'])
                        ->count(),
            );
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'plan_limit',
                'message' => $e->getMessage() ?: 'Device limit reached on your plan.',
            ], 402);
        }

        // Normalise: country_code stored as bare digits ("91", not "+91"),
        // phone_number stored as bare local part with cc prefix stripped
        // once. Same normaliser the web uses to avoid double-prefix bugs.
        $cc    = preg_replace('/\D+/', '', (string) $data['country_code']);
        $local = preg_replace('/\D+/', '', (string) $data['phone_number']);
        if ($cc !== '' && str_starts_with($local, $cc)) {
            $local = substr($local, strlen($cc));
        }
        if ($cc === '' || $local === '') {
            return response()->json(['success' => false, 'message' => 'Invalid country_code or phone_number.'], 422);
        }
        $full = $cc . $local;

        // Phone uniqueness in PHP — encrypted column can't be SQL-unique.
        $taken = Device::query()->forCurrentWorkspace()->get(['id', 'phone_number', 'country_code'])
            ->first(fn ($d) => preg_replace('/\D+/', '', $d->country_code . $d->phone_number) === $full);
        if ($taken) {
            return response()->json([
                'success'     => false,
                'message'     => 'A device with that phone number already exists.',
                'existing_id' => $taken->id,
            ], 422);
        }

        $device = Device::create([
            'user_id'                => $user->id,
            'assigned_user_id'       => $user->id,
            'workspace_id'           => $wsId ?: null,
            'device_name'            => $data['device_name'],
            'country_code'           => $cc,
            'phone_number'           => $local,
            'region'                 => $data['region'] ?? null,
            'status'                 => 'disconnected',
            'active'                 => false,
            'activate_after_pairing' => (bool) ($data['activate_after_pairing'] ?? true),
        ]);

        // Kick off Node pairing immediately so the app can poll QR/status.
        $qr     = $this->bridgeInitialize($full);
        $status = $qr['status'] ?? 'pending';

        return response()->json([
            'success' => true,
            'message' => 'Device created — scan the QR or use the pairing code to connect.',
            'data'    => [
                'device'    => $this->devicePayload($device),
                'qr'        => $qr['qr'] ?? null,        // base64 data URL or raw SVG depending on Node
                'status'    => $status,                  // 'qr_ready' | 'connected' | 'pending'
                'phone'     => $full,
                'qr_url'        => "/api/app/devices/{$device->id}/qr",
                'pair_code_url' => "/api/app/devices/{$device->id}/pair-code",
                'status_url'    => "/api/app/device-status/{$device->id}",
            ],
        ], 201);
    }

    // -----------------------------------------------------------------
    // GET /devices/{id}/qr — fetch / refresh the QR data URL for a
    // device currently in pairing. The Node bridge re-uses an existing
    // QR if one is in flight; we terminate stale sessions first when
    // the device is not connected so a fresh socket is built.
    // -----------------------------------------------------------------
    public function qr(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->forCurrentWorkspace()->find($id);
        if (! $device) return response()->json(['success' => false, 'message' => 'Device not found.'], 404);
        $phone  = $this->fullPhone($device);
        $body   = $this->bridgeInitialize($phone, $device->status !== 'connected');
        return response()->json([
            'success' => true,
            'data'    => [
                'qr'     => $body['qr']     ?? null,
                'status' => $body['status'] ?? 'pending',
                'phone'  => $phone,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // GET /devices/{id}/pair-code — request an 8-digit pairing code
    // (alternative to QR for users who can't scan).
    // -----------------------------------------------------------------
    public function pairCode(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->forCurrentWorkspace()->find($id);
        if (! $device) return response()->json(['success' => false, 'message' => 'Device not found.'], 404);
        $phone  = $this->fullPhone($device);
        $base   = $this->nodeBaseUrl();
        if ($base === '') {
            return response()->json(['success' => false, 'message' => 'Node bridge URL is not configured.'], 500);
        }
        try {
            $r = Http::timeout(15)->acceptJson()
                ->get(rtrim($base, '/') . '/api/get-pairing-code/' . urlencode($phone));
            $j = $r->json() ?: [];
            return response()->json([
                'success' => $r->successful(),
                'data'    => [
                    'code'   => $j['code'] ?? $j['pairing_code'] ?? null,
                    'phone'  => $phone,
                    'status' => $j['status'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    // -----------------------------------------------------------------
    // POST /devices/connect-twilio — connect a Twilio (WhatsApp) sender for
    // the current workspace. Mobile mirror of WaConnectController@saveTwilio:
    // verifies the creds against Twilio, upserts the workspace's provider=twilio
    // WaProviderConfig (encrypted creds), and makes it the primary engine.
    // Blank SID/token/from fall back to the platform-admin defaults (same as web).
    // -----------------------------------------------------------------
    public function connectTwilio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_sid' => 'nullable|string|max:64',
            'auth_token'  => 'nullable|string|max:128',
            'from_number' => 'nullable|string|max:32',
            'sandbox'     => 'nullable|boolean',
        ]);

        $user        = $request->user();
        $workspaceId = (int) ($user->current_workspace_id ?? 0);
        if (! $workspaceId) {
            return response()->json(['success' => false, 'message' => 'Pick or create a workspace first.'], 422);
        }

        // Blank fields inherit the platform admin's shared Twilio creds.
        $accountSid = $data['account_sid'] ?: (string) \App\Models\SystemSetting::get('twilio_account_sid', '');
        $authToken  = $data['auth_token']  ?: (string) \App\Models\SystemSetting::get('twilio_auth_token', '');
        $fromNumber = $data['from_number'] ?: (string) \App\Models\SystemSetting::get('twilio_whatsapp_number', '');
        $sandbox    = (bool) ($data['sandbox'] ?? false);

        if ($accountSid === '' || $authToken === '' || $fromNumber === '') {
            return response()->json([
                'success' => false,
                'message' => 'Account SID, auth token and WhatsApp From number are all required.',
            ], 422);
        }

        // Cheap creds check — GET /Accounts/{sid}.json returns 200 when SID+token match.
        try {
            $res = Http::withBasicAuth($accountSid, $authToken)
                ->timeout(8)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}.json");
            if (! $res->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Twilio rejected those credentials: ' . ($res->json('message') ?? ('HTTP ' . $res->status())),
                ], 422);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not reach Twilio: ' . $e->getMessage()], 502);
        }

        // Key on (workspace, provider) so this only touches the Twilio row.
        $config = \App\Models\WaProviderConfig::firstOrNew([
            'workspace_id' => $workspaceId,
            'provider'     => \App\Enums\WaProvider::Twilio->value,
        ]);

        // Unified device-cap enforcement — only for a brand-new number.
        if (! $config->exists) {
            try {
                \App\Services\PlanLimitGuard::check(
                    $user->currentWorkspace,
                    'device_limit',
                    Device::query()->forWorkspace($workspaceId, $user->id)->count()
                        + \App\Models\WaProviderConfig::query()->forWorkspace($workspaceId)
                            ->whereIn('provider', ['waba', 'twilio'])->count(),
                );
            } catch (\App\Exceptions\PlanLimitReachedException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage(), 'reason' => 'plan_limit'], 403);
            }
        }

        $config->fill([
            'provider'      => \App\Enums\WaProvider::Twilio->value,
            'status'        => \App\Models\WaProviderConfig::STATUS_CONNECTED,
            'phone_number'  => $fromNumber,
            'display_label' => 'Twilio · ' . ($sandbox ? 'Sandbox' : 'Production'),
            'connected_at'  => now(),
            'is_primary'    => true,
            'meta_json'     => ['sandbox' => $sandbox],
        ]);
        $config->setCreds([
            'account_sid' => $accountSid,
            'auth_token'  => $authToken,
            'from_number' => $fromNumber,
            'sandbox'     => $sandbox,
        ]);
        $config->save();

        // Demote other rows so WorkspaceEngine resolves to Twilio immediately.
        \App\Models\WaProviderConfig::where('workspace_id', $workspaceId)
            ->where('id', '!=', $config->id)
            ->update(['is_primary' => false]);
        \App\Services\WorkspaceEngine::flush();
        \App\Services\NodeCacheBuster::bustWorkspace($workspaceId);

        return response()->json([
            'success' => true,
            'message' => 'Twilio connected. You can start sending now.',
            'data'    => [
                'id'           => $config->id,
                'provider'     => 'twilio',
                'phone_number' => $fromNumber,
                'sandbox'      => $sandbox,
                'status'       => 'connected',
                'label'        => $config->display_label,
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // POST /devices/connect-waba — connect a WhatsApp Cloud API (WABA) number
    // for the current workspace by pasting Meta credentials (Phone Number ID,
    // WABA ID, Access Token). Mobile mirror of the web manual-connect: probes
    // the number, subscribes+overrides the WABA callback to OUR inbound URL,
    // registers it on the Cloud API, and saves the encrypted provider=waba row.
    // -----------------------------------------------------------------
    public function connectWaba(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => 'required|string|max:40',
            'waba_id'         => 'required|string|max:40',
            'business_id'     => 'nullable|string|max:40',
            'access_token'    => 'required|string|max:1024',
            'display_label'   => 'nullable|string|max:120',
            'app_id'          => 'nullable|string|max:64',
            'app_secret'      => 'nullable|string|max:128',
        ]);

        $user = $request->user();
        $wsId = (int) ($user->current_workspace_id ?? 0);
        if (! $wsId) return response()->json(['success' => false, 'message' => 'No active workspace.'], 422);

        // Unified device-cap (Baileys + WABA + Twilio) — new numbers only.
        $isReconnect = \App\Models\WaProviderConfig::query()->forWorkspace($wsId)->where('provider', 'waba')->get()
            ->contains(function ($row) use ($data) {
                $m = (array) ($row->meta_json ?? []);
                return (string) ($m['waba_id'] ?? '') === (string) $data['waba_id']
                    && (string) ($m['phone_number_id'] ?? '') === (string) $data['phone_number_id'];
            });
        if (! $isReconnect) {
            try {
                \App\Services\PlanLimitGuard::check(
                    $user->currentWorkspace,
                    'device_limit',
                    Device::query()->forWorkspace($wsId, $user->id)->count()
                        + \App\Models\WaProviderConfig::query()->forWorkspace($wsId)
                            ->whereIn('provider', ['waba', 'twilio'])->count(),
                );
            } catch (\App\Exceptions\PlanLimitReachedException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage(), 'reason' => 'plan_limit'], 403);
            }
        }

        $version = (string) \App\Models\SystemSetting::get('waba_graph_api_version', 'v23.0');
        $base    = 'https://graph.facebook.com/' . ltrim($version, '/');

        // Auto-extend a short-lived token to long-lived when app creds are known.
        $exAppId  = ! empty($data['app_id'])     ? trim($data['app_id'])     : (string) \App\Models\SystemSetting::get('waba_app_id', '');
        $exSecret = ! empty($data['app_secret']) ? trim($data['app_secret']) : (string) \App\Models\SystemSetting::get('waba_app_secret', '');
        if ($exAppId !== '' && $exSecret !== '') {
            try {
                $ex = Http::acceptJson()->timeout(15)->get($base . '/oauth/access_token', [
                    'grant_type' => 'fb_exchange_token', 'client_id' => $exAppId,
                    'client_secret' => $exSecret, 'fb_exchange_token' => trim($data['access_token']),
                ]);
                $long = (string) ($ex->json('access_token') ?? '');
                if ($ex->successful() && $long !== '') $data['access_token'] = $long;
            } catch (\Throwable $e) { /* keep pasted token */ }
        }

        // Probe the phone number — validates the token + pulls its fields.
        try {
            $probe = Http::withToken($data['access_token'])->acceptJson()->timeout(15)
                ->get("{$base}/{$data['phone_number_id']}", [
                    'fields' => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier,'
                              . 'code_verification_status,name_status,account_mode,throughput,platform_type,'
                              . 'is_official_business_account,is_pin_enabled,last_onboarded_time',
                ]);
            if (! $probe->successful()) {
                $err = (array) $probe->json('error', []);
                return response()->json(['success' => false, 'message' => 'Meta rejected the credentials: ' . ($err['message'] ?? ('HTTP ' . $probe->status()))], 422);
            }
            $info = $probe->json();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not reach Meta: ' . $e->getMessage()], 502);
        }

        if (strtoupper((string) ($info['platform_type'] ?? '')) === 'ON_PREMISE') {
            return response()->json([
                'success' => false,
                'message' => 'This number (' . ($info['display_phone_number'] ?? $data['phone_number_id']) . ') is still on WhatsApp On-Premises API, which Meta retired. Verify + register it on the Cloud API in WhatsApp Manager, then connect again.',
            ], 422);
        }

        // Auto-resolve Business Manager id if not supplied.
        $businessId = $data['business_id'] ?? null;
        if (! $businessId) {
            try {
                $owner = Http::withToken($data['access_token'])->acceptJson()->timeout(10)
                    ->get("{$base}/{$data['waba_id']}", ['fields' => 'owner_business_info{id,name}']);
                $businessId = $owner->json('owner_business_info.id') ?: null;
            } catch (\Throwable $e) { /* best-effort */ }
        }

        // Subscribe THIS WABA to webhooks AND override its callback to our inbound
        // endpoint (atomic single call — the form that historically wired inbound
        // reliably), with the (#100) "must be subscribed" retry on the propagation
        // race that failed the first connect.
        $verifyTok    = (string) \App\Models\SystemSetting::get('waba_webhook_verify_token', '');
        if ($verifyTok === '') {
            $verifyTok = \Illuminate\Support\Str::random(40);
            \App\Models\SystemSetting::set('waba_webhook_verify_token', $verifyTok, 'string', 'Webhook verify token Meta echoes on subscription (auto-generated).');
        }
        $overrideUrl  = url('/webhooks/whatsapp/inbound');
        $endpoint     = "{$base}/{$data['waba_id']}/subscribed_apps";
        $webhookWired = true;
        $doOverride   = fn () => Http::withToken($data['access_token'])->acceptJson()->timeout(15)
            ->post($endpoint, ['override_callback_uri' => $overrideUrl, 'verify_token' => $verifyTok]);
        try {
            $sub = $doOverride();
            if (! $sub->successful()) {
                $err     = (array) $sub->json('error', []);
                $subcode = (int) ($err['error_subcode'] ?? 0);
                if ($subcode === 1349174) {
                    return response()->json(['success' => false, 'message' => 'Token is missing the whatsapp_business_management permission. Generate a System-User token WITH that scope and paste it again.'], 422);
                }
                if ($subcode === 100 || (int) ($err['code'] ?? 0) === 100) {
                    // Propagation race — subscribe plainly first, then override.
                    Http::withToken($data['access_token'])->acceptJson()->timeout(15)->post($endpoint);
                    usleep(700000);
                    $sub = $doOverride();
                }
                $webhookWired = $sub->successful();
            }
        } catch (\Throwable $e) {
            $webhookWired = false;
            Log::warning('[App\Device] WABA subscribe/override threw', ['err' => $e->getMessage()]);
        }

        // Upsert the config — re-connecting the SAME number updates in place.
        $cfg = \App\Models\WaProviderConfig::query()->forWorkspace($wsId)->where('provider', 'waba')->get()
            ->first(function ($row) use ($data) {
                $m = (array) ($row->meta_json ?? []);
                return (string) ($m['waba_id'] ?? '') === (string) $data['waba_id']
                    && (string) ($m['phone_number_id'] ?? '') === (string) $data['phone_number_id'];
            }) ?: new \App\Models\WaProviderConfig();
        $isNew    = ! $cfg->exists;
        $existing = \App\Models\WaProviderConfig::query()->forWorkspace($wsId)->where('provider', 'waba')->count();
        $cfg->workspace_id  = $wsId;
        $cfg->provider      = 'waba';
        $cfg->status        = \App\Models\WaProviderConfig::STATUS_CONNECTED;
        $cfg->phone_number  = (string) ($info['display_phone_number'] ?? '');
        $cfg->display_label = (string) ($data['display_label'] ?: ($info['verified_name'] ?? ''));
        $cfg->meta_json     = [
            'waba_id'                  => $data['waba_id'],
            'phone_number_id'          => $data['phone_number_id'],
            'business_id'              => $businessId,
            'verified_name'            => $info['verified_name']            ?? null,
            'display_phone_number'     => $info['display_phone_number']     ?? null,
            'quality_rating'           => $info['quality_rating']           ?? null,
            'messaging_limit_tier'     => $info['messaging_limit_tier']     ?? null,
            'code_verification_status' => $info['code_verification_status'] ?? null,
            'name_status'              => $info['name_status']              ?? null,
            'account_mode'             => $info['account_mode']             ?? null,
            'platform_type'            => $info['platform_type']            ?? null,
            'connected_via'            => 'manual_app',
        ];
        $cfg->connected_at   = now();
        $cfg->last_health_at = now();
        if ($isNew) $cfg->is_primary = ($existing === 0);
        $cfg->setCreds(array_filter([
            'access_token' => $data['access_token'],
            'app_id'       => ! empty($data['app_id'])     ? trim($data['app_id'])     : null,
            'app_secret'   => ! empty($data['app_secret']) ? trim($data['app_secret']) : null,
        ]));
        $cfg->save();

        // Register on Cloud API (best-effort — a failure here must not block the
        // connect; the operator can register later) then flush engine + Node cache.
        try { app(\App\Services\Waba\WabaNumberRegistrar::class)->register($cfg); } catch (\Throwable $e) {}
        \App\Services\WorkspaceEngine::flush();
        \App\Services\NodeCacheBuster::bustWorkspace($wsId);

        $label = $cfg->phone_number ?: $cfg->display_label;
        return response()->json([
            'success' => true,
            'message' => $webhookWired
                ? 'Connected WABA number "' . $label . '". Two-way messaging is live.'
                : 'Connected WABA number "' . $label . '" for sending. Inbound is NOT wired yet — set Callback URL ' . $overrideUrl . ' + verify token "' . $verifyTok . '" in your Meta app (token needs whatsapp_business_management), or connect via Embedded Signup.',
            'data' => [
                'id'            => $cfg->id,
                'provider'      => 'waba',
                'phone_number'  => $cfg->phone_number,
                'label'         => $cfg->display_label,
                'inbound_wired' => $webhookWired,
                'status'        => 'connected',
            ],
        ], 200);
    }

    // -----------------------------------------------------------------
    // POST /devices/{id}/disconnect — terminate the Baileys session on
    // Node so the device shows as disconnected. Keeps the local row so
    // the user can re-pair later without re-entering the number.
    // -----------------------------------------------------------------
    public function disconnect(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->forCurrentWorkspace()->find($id);
        if (! $device) return response()->json(['success' => false, 'message' => 'Device not found.'], 404);
        $phone  = $this->fullPhone($device);
        $base   = $this->nodeBaseUrl();
        if ($base !== '') {
            try {
                Http::timeout(10)->acceptJson()
                    ->get(rtrim($base, '/') . '/api/terminate-client/' . urlencode($phone));
            } catch (\Throwable $e) {
                Log::warning('[App\Device] disconnect Node call failed', ['device' => $id, 'err' => $e->getMessage()]);
            }
        }
        $device->forceFill(['active' => false, 'status' => 'disconnected'])->save();
        return response()->json([
            'success' => true,
            'message' => 'Device disconnected.',
            'data'    => $this->devicePayload($device->refresh()),
        ]);
    }

    // -----------------------------------------------------------------
    // DELETE /devices/{id} — disconnect AND remove the device row.
    // Closes any open conversations attached to it so the team inbox
    // doesn't keep showing dead threads as live.
    // -----------------------------------------------------------------
    public function destroy(Request $request, int $id): JsonResponse
    {
        $device = Device::query()->forCurrentWorkspace()->find($id);
        if (! $device) return response()->json(['success' => false, 'message' => 'Device not found.'], 404);

        $phone = $this->fullPhone($device);
        $base  = $this->nodeBaseUrl();
        if ($base !== '') {
            try {
                Http::timeout(10)->acceptJson()
                    ->get(rtrim($base, '/') . '/api/terminate-client/' . urlencode($phone));
            } catch (\Throwable $e) {
                Log::warning('[App\Device] destroy Node call failed', ['device' => $id, 'err' => $e->getMessage()]);
            }
        }

        // Close conversations attached to this device — history stays
        // readable but the thread drops out of the active queue.
        \App\Models\Conversation::where('device_id', $device->id)
            ->whereIn('inbox_status', ['open', 'pending', 'snoozed'])
            ->update(['inbox_status' => 'closed', 'resolved_at' => now()]);

        $device->delete();
        return response()->json(['success' => true, 'message' => 'Device removed.', 'data' => ['id' => $id]]);
    }

    // =================================================================
    // Helpers — Node bridge URL + initialize call
    // =================================================================

    /** Read the Node bridge URL from SystemSetting first, env second. */
    private function nodeBaseUrl(): string
    {
        return (string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', ''));
    }

    /**
     * Call POST /api/initialize-client on the Node bridge to start (or
     * re-start) the Baileys session for this phone. When
     * `terminateFirst` is true we hit /api/terminate-client first so a
     * fresh socket + new QR is generated for a stale session.
     */
    private function bridgeInitialize(string $phone, bool $terminateFirst = false): array
    {
        $base = $this->nodeBaseUrl();
        if ($base === '' || $phone === '') {
            return ['qr' => null, 'status' => 'pending'];
        }
        try {
            if ($terminateFirst) {
                try {
                    Http::timeout(8)->acceptJson()
                        ->get(rtrim($base, '/') . '/api/terminate-client/' . urlencode($phone));
                } catch (\Throwable $e) { /* best-effort */ }
            }
            $r = Http::timeout(15)->acceptJson()
                ->get(rtrim($base, '/') . '/api/initialize-client/' . urlencode($phone));
            return $r->json() ?: ['qr' => null, 'status' => 'pending'];
        } catch (\Throwable $e) {
            Log::warning('[App\Device] bridgeInitialize failed', ['phone' => $phone, 'err' => $e->getMessage()]);
            return ['qr' => null, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Shape one Device row into the app's device payload. Keeps the old
     * keys (phone_number, device_name, active) and adds the columns the
     * batch lists. device_name + phone_number are decrypted by the model
     * accessor before they reach here.
     */
    private function devicePayload(Device $d): array
    {
        return [
            'id'               => $d->id,
            'device_name'      => (string) ($d->device_name ?? ''),
            'country_code'     => $d->country_code ?: null,
            'phone_number'     => $this->fullPhone($d),
            'region'           => $d->region ?: null,
            'active'           => (int) ((bool) $d->active),
            'status'           => $d->status,
            'last_seen_at'     => optional($d->last_seen_at)->toIso8601String(),
            'assigned_user_id' => $d->assigned_user_id,
            'engine'           => 'baileys',
            'sender_key'       => 'baileys:' . $d->id,
        ];
    }

    /**
     * The workspace's connected NON-Baileys senders (WABA / Twilio) mapped
     * into the same payload shape as a device row, so the app's device list
     * shows every number the workspace can actually send from.
     *
     * Engine set is resolved exactly like the web /devices page: the plan's
     * available engines PLUS any engine the workspace has genuinely connected
     * a provider for — otherwise a number connected before a plan change (or
     * outside the enabled_engines subset) silently disappears from the app.
     *
     * Baileys is filtered out because Device::forCurrentWorkspace() already
     * returned those rows, and it returns them in EVERY status — senders()
     * only yields connected ones, so keeping both would drop the pairing/QR
     * rows the app needs to show.
     */
    private function connectedChannelPayloads(int $wsId): \Illuminate\Support\Collection
    {
        if ($wsId <= 0) {
            return collect();
        }

        try {
            // EVERY engine, not just the WhatsApp family. availableFor() returns
            // baileys/waba/twilio only, so passing it meant the app's account
            // picker could never show a Telegram bot, Instagram account, LINE /
            // WeChat / Viber channel, Facebook Page or SMS sender — even with
            // those channels connected and visible in the inbox. Passing the
            // full set is safe: each branch inside senders() re-checks its own
            // platform toggle AND that channel's hasConnected() gate, so a
            // disabled or unconnected channel still yields nothing.
            $engines = \App\Services\WorkspaceEngine::allEngines();

            return \App\Services\WorkspaceEngine::senders($wsId, $engines)
                ->filter(fn ($s) => ($s['engine'] ?? '') !== 'baileys' && ! empty($s['phone']))
                ->map(fn ($s) => [
                    // Provider-config id — NOT a devices.id. Paired with
                    // `engine`/`sender_key` so the app never confuses the two.
                    'id'               => $s['id'] ?? null,
                    'device_name'      => (string) ($s['label'] ?? ''),
                    'country_code'     => null,
                    'phone_number'     => (string) $s['phone'],
                    'region'           => null,
                    'active'           => 1,
                    'status'           => 'connected',
                    'last_seen_at'     => null,
                    'assigned_user_id' => null,
                    'engine'           => (string) $s['engine'],
                    'sender_key'       => (string) ($s['key'] ?? ($s['engine'] . ':' . ($s['id'] ?? ''))),
                ])
                ->values();
        } catch (\Throwable $e) {
            // Never let a provider-side problem take down the device list —
            // the Baileys rows are still useful on their own.
            Log::warning('WaDesk app getDevices: channel merge skipped: ' . $e->getMessage(), [
                'workspace_id' => $wsId,
            ]);

            return collect();
        }
    }

    /**
     * Resolve a device by id OR bare phone number, always scoped to the
     * current workspace so a user can never read another workspace's
     * device. The route param is numeric for an id but may be a phone
     * number on legacy app builds — handle both.
     */
    private function resolveDevice($key): ?Device
    {
        $key = (string) $key;

        // Numeric + short → treat as a row id first.
        if (ctype_digit($key) && strlen($key) <= 12) {
            $byId = Device::query()->forCurrentWorkspace()->find((int) $key);
            if ($byId) {
                return $byId;
            }
        }

        // Otherwise match on the full digits-only phone number. Phone is
        // encrypted-at-rest so we can't WHERE on ciphertext — hydrate the
        // workspace's devices and compare in PHP.
        $target = preg_replace('/\D+/', '', $key);
        if ($target === '') {
            return null;
        }

        return Device::query()
            ->forCurrentWorkspace()
            ->get()
            ->first(fn (Device $d) => $this->fullPhone($d) === $target);
    }

    /** Full digits-only E.164 phone (country code + national number). */
    private function fullPhone(Device $d): string
    {
        return preg_replace('/\D+/', '', (string) ($d->country_code . $d->phone_number)) ?: '';
    }

    /**
     * Digits-only phone of the WABA/Twilio account the request selected, or ''
     * when nothing resolved. Used where a device would normally supply the
     * number — a provider account is not a Device, so fullPhone() can't help.
     * Reads the id the workspace middleware already validated, so no
     * cross-tenant check is needed here.
     */
    private function selectedAccountPhone(Request $request): string
    {
        $cfgId = (int) $request->attributes->get('app_provider_config_id', 0);
        if ($cfgId <= 0) {
            return '';
        }

        $phone = \App\Models\WaProviderConfig::whereKey($cfgId)->value('phone_number');

        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    /**
     * Derive a 0-100 progress value from the device status so the old
     * polling shape ({status, progress}) keeps working. We don't persist
     * a `progress` column, so this is a stable mapping, not live telemetry.
     */
    private function progressFor(Device $d): int
    {
        return match ($d->status) {
            'connected'  => 100,
            'needs_pair' => 30,
            'failed'     => 0,
            default      => $d->active ? 100 : 0,
        };
    }

    /**
     * Best-effort live contact fetch from the Node bridge, reshaped to the
     * app's contact rows. Returns null (not an error) when no bridge is
     * configured or the call fails, so the caller falls back to stored
     * contacts. Mirrors the old getDeviceContacts proxy + formatting.
     */
    private function fetchLiveContacts(string $phone): ?array
    {
        if ($phone === '' || ! function_exists('wd_node_url')) {
            return null;
        }

        $base = '';
        try {
            $base = (string) wd_node_url();
        } catch (\Throwable $e) {
            $base = '';
        }
        if ($base === '') {
            return null;
        }

        try {
            $res = Http::timeout(15)->acceptJson()
                ->get(rtrim($base, '/') . '/api/get-contacts/' . urlencode($phone));
            if (! $res->successful()) {
                return null;
            }
            $data = $res->json() ?: [];
        } catch (\Throwable $e) {
            return null;
        }

        $rawContacts = $data['contacts'] ?? null;
        if (! is_array($rawContacts)) {
            return null;
        }

        $bridgePhone = (string) ($data['phoneNumber'] ?? $phone);
        $formatted = [];
        foreach ($rawContacts as $waId => $contact) {
            $contact = is_array($contact) ? $contact : [];
            $number  = str_replace('@s.whatsapp.net', '', (string) $waId);

            $formatted[] = [
                'name'        => (string) ($contact['name'] ?? ''),
                'number'      => $number,
                'pushname'    => (string) ($contact['verifiedName'] ?? ''),
                'isMyContact' => ($number === $bridgePhone),
                'avatar'      => $contact['profilePicUrl'] ?? null,
            ];
        }

        $data['contacts']    = $formatted;
        $data['phoneNumber'] = $bridgePhone;
        $data['source']      = 'bridge';

        return $data;
    }
}
