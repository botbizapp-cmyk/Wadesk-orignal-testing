<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile-app workspace/device scoping.
 *
 * A browser session carries the active workspace in users.current_workspace_id,
 * flipped by the header dropdown. The Flutter app has no session — one Bearer
 * token, many workspaces — so it tells us which workspace (and, optionally,
 * which device) a request is about via headers:
 *
 *   X-Workspace-Id: <id>     (fallback: `workspace_id` in the body/query)
 *   X-Device-Id:    <id>     (fallback: `device_id`   in the body/query)
 *
 * When X-Workspace-Id is present we VERIFY the caller is a joined member of that
 * workspace, then set current_workspace_id IN MEMORY for this request only (no
 * save) so every existing `forCurrentWorkspace()` scope + `current_workspace_id`
 * read resolves to the requested workspace. No header → the token's stored
 * current_workspace_id is used, exactly as before (so /workspaces itself, and
 * every legacy call, keep working). A header naming a workspace the user isn't a
 * member of is a hard 403 — never a silent cross-tenant leak.
 *
 * X-Device-Id is validated to belong to the resolved workspace and stashed on
 * the request (`app_device_id`) for endpoints that want an implicit device; it
 * never hard-fails when absent (most device endpoints take the id in the path).
 */
class ResolveAppWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);   // auth:sanctum handles the 401 before us
        }

        $wsId = (int) ($request->header('X-Workspace-Id') ?: $request->input('workspace_id') ?: 0);
        if ($wsId > 0) {
            $isMember = DB::table('workspace_user')
                ->where('workspace_id', $wsId)
                ->where('user_id', $user->id)
                ->whereNotNull('joined_at')
                ->exists();

            if (! $isMember) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not a member of that workspace.',
                ], 403);
            }

            // Scope THIS request ONLY. Set it in memory, then immediately sync the
            // attribute's "original" so Eloquent treats it as NOT dirty. Without
            // this, any unrelated $user->save() later in the request (e.g. a
            // profile update) would PERSIST the app's chosen workspace into
            // users.current_workspace_id — the SAME column the WEB dashboard reads
            // — and silently switch the user's web session to another workspace
            // (their devices/number would "vanish" from the web). The app is
            // header-driven and must NEVER write that column.
            $user->current_workspace_id = $wsId;
            $user->syncOriginalAttribute('current_workspace_id');
        }

        $inputDevice  = (int) ($request->input('device_id') ?: 0);
        $headerDevice = (int) ($request->header('X-Device-Id') ?: 0);

        // Body/header mismatch guard. The app keeps the picked device in lockstep
        // with X-Device-Id, so an EXPLICIT body `device_id` that differs from the
        // header is a tampered/stale request (e.g. pinned to A but posting a send
        // from B). Reject rather than silently letting the body win — this closes
        // the "send as another device" gap on create-queue / campaigns / send-*.
        if ($inputDevice > 0 && $headerDevice > 0 && $inputDevice !== $headerDevice) {
            return response()->json([
                'success' => false,
                'message' => 'device_id does not match the selected device (X-Device-Id).',
            ], 422);
        }

        // MULTI-ENGINE SELECTION. A bare id only ever addresses the `devices`
        // table (Unofficial API). A WABA/Twilio number is a wa_provider_configs
        // row, and the two tables' ids COLLIDE — so there was no way for the app
        // to pin a send to a Meta number, and a header meant as "WABA #89" would
        // silently resolve to Unofficial device #89 or be dropped without error.
        // The composite `waba:89` form (what /get-devices now returns as
        // `sender_key`) is unambiguous, so we resolve it against the provider
        // table instead and stash it for the endpoints that read an account.
        $rawKey = (string) ($request->input('sender_key')
            ?: $request->header('X-Sender-Key')
            ?: $request->header('X-Device-Id'));
        $keyDevice = 0;
        if (str_contains($rawKey, ':')) {
            [$engine, $rawId] = explode(':', $rawKey, 2);
            $rawId = trim($rawId);

            // EVERY non-Unofficial channel, not just the WhatsApp family — a
            // Telegram bot / LINE / WeChat / Viber / Instagram / Facebook / SMS
            // account is selected the same way and must scope the same lists.
            $channelEngines = array_values(array_diff(
                \App\Services\WorkspaceEngine::allEngines(),
                [\App\Services\WorkspaceEngine::ENGINE_BAILEYS]
            ));

            if (ctype_digit($rawId) && in_array($engine, $channelEngines, true)) {
                // Same reasoning as the body/header mismatch guard above: an
                // explicit device_id alongside a provider sender key means the
                // request is pinned to two different numbers at once.
                if ($inputDevice > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'device_id conflicts with the selected sender (sender_key).',
                    ], 422);
                }

                // Engine + id, always as a PAIR. Rows key their sender the same
                // polymorphic way (device_id + provider), and ids collide across
                // the per-channel tables, so an id on its own means nothing.
                // No existence check here: every consumer already scopes by
                // workspace, so an id from another tenant simply matches nothing.
                $request->attributes->set('app_sender_engine', $engine);
                $request->attributes->set('app_sender_id', (int) $rawId);
                $request->attributes->set('app_sender_key', $engine . ':' . $rawId);

                // app_provider_config_id keeps its narrower meaning — a real
                // wa_provider_configs row — because WABA-only consumers
                // (templates, contacts) resolve accounts against that table.
                if (in_array($engine, ['waba', 'twilio'], true)) {
                    $okCfg = \App\Models\WaProviderConfig::query()
                        ->where('workspace_id', (int) $user->current_workspace_id)
                        ->whereKey((int) $rawId)
                        ->where('provider', $engine)
                        ->exists();
                    if ($okCfg) {
                        $request->attributes->set('app_provider_config_id', (int) $rawId);
                        if ($request->input('account_id') === null) {
                            $request->merge(['account_id' => (int) $rawId]);
                        }
                    }
                }

                // A channel account id is never a devices.id — skip the device
                // lookup below so we can't half-resolve against the wrong table.
                return $next($request);
            }

            // `baileys:127` — the composite form of a plain device. Unwrap it
            // so an app that echoes back the sender_key from /get-devices keeps
            // selecting the device instead of silently selecting nothing.
            if (ctype_digit($rawId) && $engine === 'baileys') {
                $keyDevice = (int) $rawId;
            }
        }

        $deviceId     = $inputDevice ?: $headerDevice ?: $keyDevice;
        if ($deviceId > 0) {
            // Only trust a device the resolved workspace actually owns.
            $ok = \App\Models\Device::query()
                ->forWorkspace((int) $user->current_workspace_id, (int) $user->id)
                ->whereKey($deviceId)
                ->exists();
            if ($ok) {
                $request->attributes->set('app_device_id', $deviceId);
                // Selected via header (not already in the body): expose it as the
                // default `device_id` input so EVERY endpoint that reads device_id
                // (quick-message, campaigns, chats, groups, autoreplies) sends from
                // the picked device/engine — the multi-device/multi-engine parity
                // the web gets from its device picker. An explicit body value wins.
                if ($inputDevice === 0) {
                    $request->merge(['device_id' => $deviceId]);
                }
            } else {
                // Not a device in this workspace. Older app builds send the BARE
                // id from /get-devices rather than the composite `sender_key`,
                // and for a WABA / Telegram / LINE / … account that id belongs
                // to a different table — so the selection resolved to nothing at
                // all and every list silently came back UNSCOPED (all of the
                // workspace's rules instead of the selected account's).
                //
                // Resolve it against the workspace's connected accounts. Only
                // when EXACTLY ONE channel owns that id: ids collide across the
                // per-channel tables, and pinning to the wrong number is worse
                // than not pinning at all.
                try {
                    $hits = \App\Services\WorkspaceEngine::senders(
                        (int) $user->current_workspace_id,
                        \App\Services\WorkspaceEngine::allEngines()
                    )->filter(fn ($s) => (int) ($s['id'] ?? 0) === $deviceId
                        && ($s['engine'] ?? '') !== \App\Services\WorkspaceEngine::ENGINE_BAILEYS)
                      ->values();

                    if ($hits->count() === 1) {
                        $hit = $hits->first();
                        $request->attributes->set('app_sender_engine', (string) $hit['engine']);
                        $request->attributes->set('app_sender_id', $deviceId);
                        $request->attributes->set('app_sender_key', $hit['engine'] . ':' . $deviceId);

                        if (in_array($hit['engine'], ['waba', 'twilio'], true)) {
                            $request->attributes->set('app_provider_config_id', $deviceId);
                            if ($request->input('account_id') === null) {
                                $request->merge(['account_id' => $deviceId]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Never let sender resolution break the request — an
                    // unscoped list is degraded, not broken.
                }
            }
        }

        return $next($request);
    }
}
