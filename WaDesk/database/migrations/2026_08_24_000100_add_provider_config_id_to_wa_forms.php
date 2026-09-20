<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #29 — record WHICH WABA a form was published on, so a 2-WABA workspace can
 * both choose the sender and see it on the form. Nullable → existing forms keep
 * their auto-picked behaviour until re-published.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_forms') && !Schema::hasColumn('wa_forms', 'provider_config_id')) {
            Schema::table('wa_forms', function (Blueprint $t) {
                $t->unsignedBigInteger('provider_config_id')->nullable()->after('meta_flow_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wa_forms') && Schema::hasColumn('wa_forms', 'provider_config_id')) {
            Schema::table('wa_forms', function (Blueprint $t) {
                $t->dropColumn('provider_config_id');
            });
        }
    }
};
