<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Finder — businesses pulled from a map source (OpenStreetMap now, Google
 * Places optionally later) for a "category + city" search. Saved per workspace
 * so the user can revisit, filter, add to Contacts, and message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source', 20)->default('osm');   // osm | google
            $table->string('external_id', 120)->nullable();  // OSM node/way id, etc.
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('phone', 40)->nullable();         // as-published
            $table->string('phone_e164', 40)->nullable();    // digits only, for wa.me
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->boolean('in_crm')->default(false);
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            // One row per (workspace, source, external business) — re-scans dedupe.
            $table->unique(['workspace_id', 'source', 'external_id'], 'leads_ws_source_ext_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
