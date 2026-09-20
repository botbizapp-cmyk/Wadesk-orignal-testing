<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DM sender → Contact capture for the non-phone social channels
 * (TikTok / Telegram / Facebook). Those senders have no phone number, so the
 * existing phone-digit contact linkage can't hold them. Give contacts an
 * optional platform identity (channel + channel_uid, unique per workspace) and
 * let a conversation link a contact directly by id (phone stays the default for
 * WhatsApp; contact_id is the fallback link for social threads).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'channel')) {
                $table->string('channel', 32)->nullable()->after('mobile_hash');
            }
            if (! Schema::hasColumn('contacts', 'channel_uid')) {
                $table->string('channel_uid', 191)->nullable()->after('channel');
            }
        });
        // Unique per workspace+channel+uid so a repeat DM re-uses the same row.
        try {
            Schema::table('contacts', function (Blueprint $table) {
                $table->unique(['workspace_id', 'channel', 'channel_uid'], 'contacts_ws_channel_uid_unique');
            });
        } catch (\Throwable $e) {
            // index may already exist on re-run — ignore.
        }

        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'contact_id')) {
                $table->unsignedBigInteger('contact_id')->nullable()->index()->after('contact_digits');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            try { $table->dropUnique('contacts_ws_channel_uid_unique'); } catch (\Throwable $e) {}
            foreach (['channel', 'channel_uid'] as $c) {
                if (Schema::hasColumn('contacts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'contact_id')) {
                $table->dropColumn('contact_id');
            }
        });
    }
};
