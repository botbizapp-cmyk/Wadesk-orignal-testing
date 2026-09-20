<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package (plan) form stores each feature as its OWN boolean column and each
 * limit as its own integer column on `packages` (Package::$casts + $fillable).
 * These Facebook channel flags must exist so they are settable in Admin →
 * Packages and enforced by the plan gate (plan:access_facebook). Guarded so a
 * re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        $features = [
            'access_facebook', 'facebook_inbox', 'facebook_posts',
            'facebook_comments', 'facebook_ai_agent',
        ];
        $limits = [
            'facebook_pages_limit', 'facebook_scheduled_posts_limit',
        ];

        Schema::table('packages', function (Blueprint $table) use ($features, $limits) {
            foreach ($features as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->boolean($col)->default(false);
                }
            }
            foreach ($limits as $col) {
                if (! Schema::hasColumn('packages', $col)) {
                    $table->integer($col)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        $cols = [
            'access_facebook', 'facebook_inbox', 'facebook_posts',
            'facebook_comments', 'facebook_ai_agent',
            'facebook_pages_limit', 'facebook_scheduled_posts_limit',
        ];

        Schema::table('packages', function (Blueprint $table) use ($cols) {
            foreach ($cols as $col) {
                if (Schema::hasColumn('packages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
