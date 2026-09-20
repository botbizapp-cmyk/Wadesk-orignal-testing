<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok Shop connection (App C — TikTok Shop Partner API). A SEPARATE app from
 * Login Kit (A) and Business Messaging (B): its own app_key/app_secret, seller
 * OAuth, and a `shop_cipher` required on every signed call. Sits alongside the
 * Shopify / WooCommerce store integrations. Tokens encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('shop_id')->nullable();
            $table->string('shop_name')->nullable();
            $table->string('shop_cipher')->nullable();   // required on every API call
            $table->string('shop_code')->nullable();
            $table->string('region', 8)->nullable();      // e.g. US, GB, ID, VN
            $table->string('seller_name')->nullable();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();

            $table->string('status')->default('connected'); // connected | expired | needs_reconnect
            $table->text('last_error')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'shop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_shops');
    }
};
