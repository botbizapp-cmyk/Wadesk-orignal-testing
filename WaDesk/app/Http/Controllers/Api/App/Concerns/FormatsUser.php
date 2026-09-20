<?php

namespace App\Http\Controllers\Api\App\Concerns;

use App\Models\User;

/**
 * Shared user-shaping for the mobile API. The app's data model reads
 * `user.image` (a full URL) + `user.is_verified`; our schema stores an
 * `avatar_path` and uses `email_verified_at`, so we bridge the two here so
 * every auth/profile endpoint returns the identical shape the app expects.
 */
trait FormatsUser
{
    protected function avatarUrl(?User $user): ?string
    {
        $p = $user?->avatar_path;
        if (empty($p)) {
            return null;
        }
        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
            return $p;
        }
        // Uploaded via this API → public/images/users; otherwise fall back
        // to the active media disk (cloud or local) for avatars saved by the
        // web app.
        if (file_exists(public_path($p))) {
            return asset($p);
        }
        return media_url($p);
    }

    protected function userPayload(User $user): array
    {
        $data = $user->toArray();
        $img = $this->avatarUrl($user);
        $data['image'] = $img;
        $data['image_url'] = $img;
        $data['is_verified'] = $user->email_verified_at ? 1 : 0;
        // App 2FA (WhatsApp OTP) on/off — the app reads this to decide whether to
        // show the 2FA screen. DISTINCT from is_verified: is_verified is a one-time
        // email/phone verification; two_factor_enabled is the ongoing 2FA switch.
        //
        // Report the EFFECTIVE state: 2FA only works while the admin's WhatsApp OTP
        // sender is active, so if the admin turned it off the app must show 2FA as
        // OFF (else it would prompt for a code that can't be sent). The user's raw
        // setting is preserved in `two_factor_setting` and auto-resumes when a sender
        // is reconnected; `two_factor_available` tells the app WHY it's suppressed.
        $otpActive = false;
        try {
            $otpActive = app(\App\Services\Auth\RegistrationOtpService::class)->isActive();
        } catch (\Throwable $e) {
            $otpActive = false;
        }
        $data['two_factor_enabled']   = (bool) $user->two_factor_enabled && $otpActive;
        $data['two_factor_setting']   = (bool) $user->two_factor_enabled;
        $data['two_factor_available'] = $otpActive;

        return $data;
    }
}
