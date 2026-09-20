<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead capture on incoming webhooks — turn a received payload into a Contact
 * (tagged as a lead) and optionally enroll it into a flow. `lead_config` holds
 * the field mapping + tag + flow; `lead_contact_id` traces which contact each
 * captured event produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_webhooks', 'lead_config')) {
                $table->json('lead_config')->nullable()->after('forward_enabled');
            }
        });

        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_webhook_events', 'lead_contact_id')) {
                $table->unsignedBigInteger('lead_contact_id')->nullable()->after('forward_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_webhooks', 'lead_config')) {
                $table->dropColumn('lead_config');
            }
        });
        Schema::table('incoming_webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_webhook_events', 'lead_contact_id')) {
                $table->dropColumn('lead_contact_id');
            }
        });
    }
};
