<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile push-token registration (FCM/APNs). The app registers its token on
 * login / token-refresh and unregisters on logout, so inbound messages can wake
 * the app when it's backgrounded/killed. Uniqueness rides on sha256(token).
 */
class DeviceTokenController extends Controller
{
    /** POST /api/app/device-tokens/register */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token'   => 'required|string|max:4096',
            'platform'    => 'nullable|in:android,ios',
            'device_info' => 'nullable|array',
        ]);

        $user  = $request->user();
        $wsId  = (int) ($user->current_workspace_id ?? 0);
        // Pinned WhatsApp device the app scopes to (validated X-Device-Id). Omit
        // it (send no X-Device-Id) to subscribe to ALL of the workspace's numbers.
        $deviceId = (int) ($request->attributes->get('app_device_id', 0) ?: $request->header('X-Device-Id', 0));

        $token = trim((string) $data['fcm_token']);

        UserDeviceToken::updateOrCreate(
            ['token_hash' => UserDeviceToken::hashFor($token)],
            [
                'user_id'      => $user->id,
                'workspace_id' => $wsId ?: null,
                'device_id'    => $deviceId ?: null,
                'fcm_token'    => $token,
                'platform'     => $data['platform'] ?? null,
                'device_info'  => $data['device_info'] ?? null,
                'last_used_at' => now(),
            ]
        );

        return response()->json(['success' => true]);
    }

    /** POST /api/app/device-tokens/unregister — call on logout. */
    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => 'required|string|max:4096']);
        UserDeviceToken::where('token_hash', UserDeviceToken::hashFor(trim((string) $data['fcm_token'])))->delete();

        return response()->json(['success' => true]);
    }
}
