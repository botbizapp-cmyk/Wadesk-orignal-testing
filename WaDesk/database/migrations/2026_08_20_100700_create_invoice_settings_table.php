<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-workspace invoice config. WhatsApp-only delivery (WABA official + Baileys
 * unofficial). WABA uses a UTILITY template with a URL button pointing at the
 * hosted PDF (no 24h window, no media upload); the template is auto-created and
 * submitted to Meta from a selected sender — mirrors the OTP template flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('numbering_prefix', 16)->default('INV');
            $table->string('proforma_prefix', 16)->default('PRO');
            $table->boolean('fy_reset')->default(true);
            $table->string('tax_label', 24)->nullable();          // GST|VAT
            $table->decimal('default_tax_rate', 6, 3)->nullable(); // own-store; null ⇒ receipt not tax-invoice
            $table->boolean('tax_inclusive_default')->default(false);
            $table->string('hsn_default', 16)->nullable();
            $table->string('logo_path', 255)->nullable();          // media_storage() path only (no remote URL)
            $table->string('brand_color', 9)->nullable();
            $table->text('footer_note')->nullable();
            $table->string('support_email', 191)->nullable();
            $table->boolean('auto_send_woocommerce')->default(false);
            $table->boolean('auto_send_shopify')->default(false);
            $table->boolean('auto_send_own')->default(false);
            $table->string('trigger_woocommerce', 16)->default('on_paid');
            $table->string('trigger_shopify', 16)->default('on_paid');
            $table->string('trigger_own', 16)->default('on_paid');
            // WhatsApp delivery: which connected sender + the approved template.
            $table->string('send_sender', 48)->nullable();         // "waba:ID" | "device:ID" (Baileys)
            $table->unsignedBigInteger('template_id_whatsapp')->nullable();
            $table->string('template_status', 16)->default('none'); // none|pending|approved|rejected
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_settings');
    }
};
