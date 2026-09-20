<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE Phase 3 — v2.1 JWT token rotation storage on `line_channels`.
 *
 * A channel can keep using the pasted long-lived token (default), OR register a
 * console Assertion Signing Key (its RSA private key + JWK `kid`) here. When set,
 * we mint short-lived, revocable v2.1 tokens (≤30 days) on demand and cache the
 * current one + its key_id + expiry — auto-refreshed by LineChannel before it
 * lapses. The private key is encrypted at rest like the other secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('line_channels')) {
            return;
        }
        Schema::table('line_channels', function (Blueprint $table) {
            if (! Schema::hasColumn('line_channels', 'assertion_kid')) {
                $table->string('assertion_kid', 128)->nullable()->after('channel_secret');
            }
            if (! Schema::hasColumn('line_channels', 'assertion_private_key')) {
                $table->text('assertion_private_key')->nullable()->after('assertion_kid'); // encrypted
            }
            if (! Schema::hasColumn('line_channels', 'rotating_token')) {
                $table->text('rotating_token')->nullable()->after('assertion_private_key'); // encrypted
            }
            if (! Schema::hasColumn('line_channels', 'rotating_token_key_id')) {
                $table->string('rotating_token_key_id', 128)->nullable()->after('rotating_token');
            }
            if (! Schema::hasColumn('line_channels', 'rotating_token_expires_at')) {
                $table->timestamp('rotating_token_expires_at')->nullable()->after('rotating_token_key_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('line_channels')) {
            return;
        }
        Schema::table('line_channels', function (Blueprint $table) {
            foreach (['assertion_kid', 'assertion_private_key', 'rotating_token', 'rotating_token_key_id', 'rotating_token_expires_at'] as $col) {
                if (Schema::hasColumn('line_channels', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
