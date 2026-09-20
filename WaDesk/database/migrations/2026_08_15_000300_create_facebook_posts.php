<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook posts composed / scheduled from WaDesk. One row per post we create
 * on a connected Page (text / link / photo / multi-photo, published now or
 * scheduled). fb_post_id is filled once Meta returns the published id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facebook_posts')) {
            return;
        }

        Schema::create('facebook_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('facebook_page_id')->index(); // facebook_pages.id
            $table->unsignedBigInteger('user_id')->nullable();        // who composed it

            $table->string('fb_post_id', 128)->nullable();            // Meta post id once live
            $table->string('type', 24)->default('text');              // text|link|photo|multi_photo
            $table->string('status', 24)->default('draft');           // draft|scheduled|published|failed

            $table->text('message')->nullable();
            $table->string('link', 2048)->nullable();
            $table->json('media_json')->nullable();                   // uploaded paths + returned media_fbids

            $table->timestamp('scheduled_publish_time')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_posts');
    }
};
