<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track WhatsApp-OTP mobile verification separately from email verification.
 *
 * Registration can verify a user in TWO ways: the classic email link
 * (email_verified_at) OR a WhatsApp OTP on their mobile (registration_otp_enabled).
 * Until now only the email column existed, so a WhatsApp-verified signup looked
 * identical to an email-verified one and the admin couldn't tell HOW someone
 * proved their identity. This adds the mobile channel's own timestamp so the
 * admin Users list can render a "Verified by · Email / WhatsApp" column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'mobile_verified_at')) {
                $table->timestamp('mobile_verified_at')->nullable()->after('email_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'mobile_verified_at')) {
                $table->dropColumn('mobile_verified_at');
            }
        });
    }
};
