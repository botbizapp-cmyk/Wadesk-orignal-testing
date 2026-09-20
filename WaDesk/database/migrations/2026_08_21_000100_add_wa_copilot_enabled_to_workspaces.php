<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI CRM Copilot — WhatsApp staff channel opt-in.
 *
 * When ON, an inbound WhatsApp message from a workspace manager/admin/owner's
 * own registered number is treated as a CRM command (routed to the Copilot)
 * instead of a customer chat. OFF by default so no workspace gets the behavior
 * without explicitly enabling it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('wa_copilot_enabled')->default(false)->after('deals_auto_min_minor');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('wa_copilot_enabled');
        });
    }
};
