<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-CRM Phase 6 — Projects. Post-sale work tracking: progress %, due dates,
 * assignee, linked to a contact / company / deal. Matches (and pairs with) the
 * competitor's Projects module. "Overdue" is derived (in_progress + past due),
 * not a stored status.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('projects')) {
            return;
        }
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('name', 255);
            $t->text('description')->nullable();          // encrypted at rest
            $t->string('status', 12)->default('in_progress')->index(); // in_progress|completed
            $t->unsignedTinyInteger('progress')->default(0);           // 0-100
            $t->unsignedBigInteger('contact_id')->nullable()->index();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('deal_id')->nullable()->index();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable()->index();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
