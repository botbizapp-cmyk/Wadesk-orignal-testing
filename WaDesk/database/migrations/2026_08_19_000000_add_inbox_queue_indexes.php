<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team-Inbox queue performance indexes.
 *
 * The queue list + badge counts filter conversations by
 * (workspace_id, archived, inbox_status) and sort by last_message_at DESC.
 * Existing composites cover the WHERE but not the ORDER BY, so MySQL filesorts
 * the matched set on every 5s poll. This adds a sort-covering composite, plus an
 * index for the Unread tab/badge/unreadSummary (`unread_count > 0`).
 *
 * Idempotent: skips an index that already exists so a re-run / resilient updater
 * never errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn (string $idx) => $this->hasIndex('conversations', $idx);

        Schema::table('conversations', function (Blueprint $table) use ($has) {
            if (! $has('conv_ws_arch_status_lastmsg_idx')) {
                $table->index(
                    ['workspace_id', 'archived', 'inbox_status', 'last_message_at'],
                    'conv_ws_arch_status_lastmsg_idx'
                );
            }
            if (! $has('conv_ws_unread_idx')) {
                $table->index(['workspace_id', 'unread_count'], 'conv_ws_unread_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            foreach (['conv_ws_arch_status_lastmsg_idx', 'conv_ws_unread_idx'] as $idx) {
                if ($this->hasIndex('conversations', $idx)) {
                    $table->dropIndex($idx);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
