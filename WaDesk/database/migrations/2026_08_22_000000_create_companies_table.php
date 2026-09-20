<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company / Organization CRM entity (Phase 1). Groups contacts + deals under one
 * business so the workspace has a B2B layer: "ACME has 3 contacts, 2 open deals,
 * ₹50k invoiced". Contacts and deals gain a nullable company_id FK. Encrypted
 * fields follow the same at-rest pattern as contacts (name/email/phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();       // creator
            $table->unsignedBigInteger('owner_user_id')->nullable(); // assigned owner
            $table->text('name');                                    // encrypted
            $table->text('email')->nullable();                       // encrypted
            $table->text('phone')->nullable();                       // encrypted
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('size_range')->nullable();                // e.g. 1-10, 11-50
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->json('custom_attributes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('workspace_id')->index();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('contact_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('company_id'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('company_id'));
        Schema::dropIfExists('companies');
    }
};
