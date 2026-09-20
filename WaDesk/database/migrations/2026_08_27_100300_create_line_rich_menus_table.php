<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINE Phase 3 — rich menus. A rich menu is the tappable image panel pinned to
 * the bottom of a LINE chat. We register it with LINE (returns richMenuId),
 * upload its image, and optionally set it as the OA default. One row per menu;
 * `areas`/`size` mirror the LINE payload so we can re-push without rebuilding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('line_rich_menus')) {
            return;
        }
        Schema::create('line_rich_menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('line_channel_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('rich_menu_id', 64)->nullable()->index(); // LINE's id once registered
            $table->string('name', 300);
            $table->string('chat_bar_text', 14)->default('Menu');
            $table->string('layout', 32)->default('full');           // preset key
            $table->text('size')->nullable();                         // json {width,height}
            $table->longText('areas')->nullable();                    // json areas[]
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_rich_menus');
    }
};
