<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facebook channel — connected Pages. One row per Facebook Page a workspace
 * manages. The user connects their Facebook ACCOUNT once (embedded signup or
 * OAuth redirect, or pastes a Page token manually); every Page that account
 * administers is stored here with its own PAGE access token (encrypted at rest
 * via the model cast). Mirrors how instagram_accounts / wa_provider_configs
 * store per-workspace channel credentials.
 *
 * Guarded so a re-run never fails on a pre-existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facebook_pages')) {
            return;
        }

        Schema::create('facebook_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();   // WaDesk workspace
            $table->unsignedBigInteger('user_id')->nullable();     // who connected it

            // Graph-API identifiers.
            $table->string('page_id', 64)->index();                // the Facebook Page id
            $table->string('name', 191)->nullable();
            $table->string('category', 191)->nullable();
            $table->string('username', 191)->nullable();           // page @username / vanity
            $table->string('picture_url', 1024)->nullable();

            // PAGE access token (derived from the long-lived USER token — Page
            // tokens minted this way do not expire). Encrypted at rest via the
            // model cast. data_access_expires_at is Meta's separate 90-day
            // data-access clock, tracked so re-auth can be prompted early.
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('data_access_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('tasks')->nullable();                     // MANAGE|CREATE_CONTENT|MODERATE|MESSAGING|ANALYZE

            $table->string('status', 24)->default('connected');    // connected | disconnected | expired | error
            $table->string('connect_method', 16)->default('oauth'); // oauth | embedded | manual
            $table->unsignedBigInteger('fan_count')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_pages');
    }
};
