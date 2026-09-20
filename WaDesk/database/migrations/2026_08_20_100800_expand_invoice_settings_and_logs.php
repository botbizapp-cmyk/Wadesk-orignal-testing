<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full seller/company identity for the invoice PDF (name, address, tax id
 * GST/VAT, registration no, extra statutory fields, signature image) + an
 * invoice activity log (issued/rendered/sent/failed/resent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_settings', function (Blueprint $table) {
            foreach ([
                'seller_name'    => fn () => $table->string('seller_name', 191)->nullable(),
                'seller_address' => fn () => $table->text('seller_address')->nullable(),
                'seller_tax_id'  => fn () => $table->string('seller_tax_id', 64)->nullable(),   // GSTIN / VAT no
                'seller_reg_no'  => fn () => $table->string('seller_reg_no', 64)->nullable(),   // company reg / CIN
                'seller_phone'   => fn () => $table->string('seller_phone', 40)->nullable(),
                'seller_extra_json' => fn () => $table->json('seller_extra_json')->nullable(),  // [{label,value}] PAN/CIN/…
                'signature_path' => fn () => $table->string('signature_path', 255)->nullable(),
                'signature_label'=> fn () => $table->string('signature_label', 64)->nullable(),
                'show_signature' => fn () => $table->boolean('show_signature')->default(false),
                'due_days'       => fn () => $table->unsignedSmallInteger('due_days')->nullable(),
            ] as $col => $add) {
                if (! Schema::hasColumn('invoice_settings', $col)) {
                    $add();
                }
            }
        });

        Schema::create('invoice_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('event', 32);                 // issued|rendered|sent|send_failed|resent|viewed|voided
            $table->string('detail', 255)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_logs');
        Schema::table('invoice_settings', function (Blueprint $table) {
            foreach (['seller_name', 'seller_address', 'seller_tax_id', 'seller_reg_no', 'seller_phone', 'seller_extra_json', 'signature_path', 'signature_label', 'show_signature', 'due_days'] as $c) {
                if (Schema::hasColumn('invoice_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
