<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook → Send Template (Phase 2). Lets an incoming webhook fire an
 * approved template to a number pulled from the payload, sending through the
 * SAME engine-aware path the inbox/campaigns use (InboxDispatcher). Config is
 * a JSON blob on the hook; each event stamps the send outcome for the inspector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_webhooks', 'template_config')) {
                $table->json('template_config')->nullable()->after('lead_config');
            }
        });

        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_webhook_events', 'template_send_status')) {
                // short status for the inspector: 'sent' | 'failed' | 'skipped:<reason>'
                $table->string('template_send_status', 64)->nullable()->after('lead_contact_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_webhooks', 'template_config')) {
                $table->dropColumn('template_config');
            }
        });
        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_webhook_events', 'template_send_status')) {
                $table->dropColumn('template_send_status');
            }
        });
    }
};
