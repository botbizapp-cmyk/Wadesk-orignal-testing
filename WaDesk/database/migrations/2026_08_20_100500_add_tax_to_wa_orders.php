<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** wa_orders tracks only shipping + discount — add tax so mappers don't re-derive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('wa_orders', 'tax_minor')) {
                $table->bigInteger('tax_minor')->default(0)->after('shipping_minor');
            }
            if (! Schema::hasColumn('wa_orders', 'tax_inclusive')) {
                $table->boolean('tax_inclusive')->default(false)->after('tax_minor');
            }
            if (! Schema::hasColumn('wa_orders', 'tax_lines_json')) {
                $table->json('tax_lines_json')->nullable()->after('tax_inclusive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wa_orders', function (Blueprint $table) {
            foreach (['tax_minor', 'tax_inclusive', 'tax_lines_json'] as $c) {
                if (Schema::hasColumn('wa_orders', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
