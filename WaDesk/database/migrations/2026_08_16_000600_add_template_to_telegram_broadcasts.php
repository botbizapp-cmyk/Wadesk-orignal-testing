<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a Telegram broadcast carry a chosen template's buttons (URL / quick-reply)
 * so a broadcast renders the same tappable keyboard the inbox template send does.
 * `buttons` is the resolved WABA/local button array; `template_id` records which
 * template it came from (display only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_broadcasts')) {
            return;
        }
        Schema::table('telegram_broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('telegram_broadcasts', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable()->after('name');
            }
            if (! Schema::hasColumn('telegram_broadcasts', 'buttons')) {
                $table->text('buttons')->nullable()->after('body'); // encrypted:array
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_broadcasts')) {
            return;
        }
        Schema::table('telegram_broadcasts', function (Blueprint $table) {
            foreach (['template_id', 'buttons'] as $col) {
                if (Schema::hasColumn('telegram_broadcasts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
