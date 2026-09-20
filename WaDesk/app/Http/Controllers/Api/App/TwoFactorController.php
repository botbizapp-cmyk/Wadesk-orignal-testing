<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Api\App\Concerns\FormatsUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOtp;
use App\Services\Auth\RegistrationOtpService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mobile-app two-factor authentication — WhatsApp OTP ONLY (no email, no PIN).
 *
 * Web parity: the user turns 2FA on with their ACCOUNT PASSWORD; from then on
 * every login returns two_factor:1 (no token), and the app must request + verify
 * a WhatsApp OTP. verify() returns the SAME token + payload a normal login does,
 * so it completes the sign-in. The OTP goes to the user's own mobile through the
 * admin-selected WhatsApp sender (RegistrationOtpService) — so 2FA requires the
 * platform's mobile-OTP sender to be configured/ON.
 *
 * This REPLACES the old PIN/passcode second factor.
 */
class TwoFactorController extends Controller
{
    use FormatsUser;

    /** POST /2fa/enable — turn 2FA ON, confirmed with the account PASSWORD. */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email|exists:users,email',
            'password' => 'required|string',
        ]);
        $user = User::where('email', $request->email)->first();

        $key = 'mobile-2fa-enable:' . $request->ip() . '|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Try again shortly.'], 429);
        }
        if (! Hash::check((string) $request->password, (string) $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json(['success' => false, 'message' => 'The password you entered is incorrect.'], 401);
        }
        RateLimiter::clear($key);

        // WhatsApp OTP is the ONLY channel, so 2FA is useless without a working
        // mobile-OTP sender — refuse to enable it when none is configured, else the
        // user would lock themselves out (login would demand an OTP that can't send).
        if (! app(RegistrationOtpService::class)->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp OTP is not available right now, so 2FA can not be enabled. Please contact support.',
            ], 422);
        }
        if (preg_replace('/\D+/', '', (string) $user->mobile) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Add a mobile number to your profile first — the 2FA code is sent to it on WhatsApp.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_enabled'      => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json([
            'success'            => true,
            'message'            => 'Two-factor authentication enabled.',
            'two_factor_enabled' => true,
        ]);
    }

    /** POST /2fa/send — send a WhatsApp OTP to the user's OWN mobile. */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);
        $user = User::where('email', $request->email)->first();

        $key = 'mobile-2fa-send:' . $request->ip() . '|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many OTP requests. Try again shortly.'], 429);
        }
        RateLimiter::hit($key, 60);

        $mobile = preg_replace('/\D+/', '', (string) $user->mobile);
        if ($mobile === '') {
            return response()->json(['success' => false, 'message' => 'No mobile number on file for this account.'], 422);
        }

        $svc = app(RegistrationOtpService::class);
        if (! $svc->isActive()) {
            return response()->json(['success' => false, 'message' => 'WhatsApp OTP is not available right now.'], 422);
        }

        $code = $svc->generateCode();
        UserOtp::updateOrCreate(
            ['user_id' => $user->id],
            ['otp' => $code, 'expires_at' => Carbon::now()->addMinutes(max(1, $svc->ttlMinutes()))],
        );

        // Country code lives with the number for most rows; send() strips to digits,
        // so passing the whole stored mobile is safe.
        $res = $svc->send('', $mobile, $code);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['success' => false, 'message' => $res['error'] ?? 'Could not send the OTP.'], 502);
        }

        return response()->json([
            'success'    => true,
            'message'    => 'OTP sent to your WhatsApp number.',
            'channel'    => 'whatsapp',
            'expires_in' => $svc->ttlMinutes() . ' minutes',
        ]);
    }

    /** POST /2fa/verify — verify the WhatsApp OTP → return the login token + payload. */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp'   => 'required|string|min:4|max:8',
        ]);
        $user = User::where('email', $request->email)->first();

        $key = 'mobile-2fa-verify:' . $request->ip() . '|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 6)) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Try again shortly.'], 429);
        }

        $row = UserOtp::where('user_id', $user->id)->first();
        if (! $row || ! hash_equals((string) $row->otp, (string) $request->otp)) {
            RateLimiter::hit($key, 60);

            return response()->json(['success' => false, 'message' => 'Invalid OTP.'], 400);
        }
        if ($row->isExpired()) {
            return response()->json(['success' => false, 'message' => 'OTP expired — request a new one.'], 400);
        }
        RateLimiter::clear($key);
        $row->delete();

        // Same token + payload a normal login returns — this COMPLETES the sign-in.
        $abilities = strtolower((string) $user->role) === 'admin' ? ['admin'] : ['*'];
        $token = $user->createToken('mobile-app', $abilities)->plainTextToken;

        return response()->json([
            'status'       => 'success',
            'token_type'   => 'Bearer',
            'access_token' => $token,
            'token'        => $token,   // back-compat alias
            'user'         => $this->userPayload($user),
            'message'      => 'Login successful',
        ]);
    }

    /** POST /2fa/disable — turn 2FA OFF, confirmed with the account PASSWORD. */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email|exists:users,email',
            'password' => 'required|string',
        ]);
        $user = User::where('email', $request->email)->first();

        $key = 'mobile-2fa-disable:' . $request->ip() . '|' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many attempts. Try again shortly.'], 429);
        }
        if (! Hash::check((string) $request->password, (string) $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json(['success' => false, 'message' => 'The password you entered is incorrect.'], 401);
        }
        RateLimiter::clear($key);

        $user->forceFill([
            'two_factor_enabled'      => false,
            'two_factor_confirmed_at' => null,
            'passcode'                => null,   // clear any legacy PIN
        ])->save();

        return response()->json([
            'success'            => true,
            'message'            => 'Two-factor authentication disabled.',
            'two_factor_enabled' => false,
        ]);
    }
}
