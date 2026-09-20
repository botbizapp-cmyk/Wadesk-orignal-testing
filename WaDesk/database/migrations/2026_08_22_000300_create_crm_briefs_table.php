<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-CRM Phase 5 — Client Briefs / decks. A generated, branded, self-contained
 * HTML brief for a contact / company / deal, with a public token (shareable link
 * + PDF, reused pattern of the invoice /i/{token}). WhatsApp-native alternative
 * to the competitor's PowerPoint bolt-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_briefs')) {
            return;
        }
        Schema::create('crm_briefs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('subject_type', 16);       // contact|company|deal
            $t->unsignedBigInteger('subject_id');
            $t->string('title', 255);
            $t->longText('html');                  // rendered self-contained deck
            $t->text('summary')->nullable();       // short blurb for lists
            $t->string('public_token', 48)->unique();
            $t->json('meta_json')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_briefs');
    }
};
