<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok channel — Phase 1 (Connect + Insights + Posting).
 *
 * A connected TikTok account (Login Kit OAuth). One workspace can connect
 * several accounts. Unlike Facebook Page tokens (non-expiring), TikTok access
 * tokens live 24h and must be refreshed with the 365-day refresh token — both
 * are stored encrypted at rest and swept for renewal on the Channels page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            // Identity (Login Kit / Display API)
            $table->string('open_id')->index();          // stable per-user-per-app id
            $table->string('union_id')->nullable();       // stable across a dev's apps
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();       // @handle (user.info.profile)
            $table->text('avatar_url')->nullable();       // TTL'd — refresh, don't rely on
            $table->text('bio')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('follower_count')->nullable();
            $table->unsignedBigInteger('following_count')->nullable();
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->unsignedBigInteger('video_count')->nullable();

            // Tokens (encrypted at rest by the model cast)
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();    // ~24h
            $table->timestamp('refresh_expires_at')->nullable();  // ~365d
            $table->json('scopes')->nullable();

            $table->string('status')->default('connected'); // connected | expired | needs_reconnect | error
            $table->string('connect_method')->default('oauth');
            $table->text('last_error')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'open_id']);
        });

        Schema::create('tiktok_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('tiktok_account_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('type')->default('video');     // video | photo
            $table->string('status')->default('draft');    // draft | processing | scheduled | published | failed
            $table->text('caption')->nullable();
            $table->json('media_json')->nullable();          // {video_url|photos[], privacy, ...}
            $table->string('publish_id')->nullable();        // TikTok async publish handle
            $table->string('tiktok_post_id')->nullable();    // final post id when known
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_posts');
        Schema::dropIfExists('tiktok_accounts');
    }
};
