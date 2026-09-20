<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-CRM Phase 3 — first-class tasks. Unlike the deal-scoped `deal_activities`
 * (type='task'), a Task can stand ALONE or attach to any CRM object
 * (contact|deal|company) via related_type/related_id, carries an assignee +
 * priority + status, and drives its own reminder sweep (reminded_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            return;
        }
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();     // creator user
            $t->unsignedBigInteger('assignee_id')->nullable()->index(); // who owns it
            $t->string('title', 255);
            $t->text('notes')->nullable();                        // encrypted at rest (Model cast)
            $t->string('priority', 8)->default('medium');         // low|medium|high
            $t->string('status', 8)->default('open')->index();    // open|done
            // Polymorphic-ish CRM link (null = standalone task).
            $t->string('related_type', 16)->nullable();           // contact|deal|company
            $t->unsignedBigInteger('related_id')->nullable();
            $t->timestamp('due_at')->nullable()->index();
            $t->timestamp('reminded_at')->nullable();             // one reminder per task (idempotent sweep)
            $t->timestamp('done_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'status', 'due_at']);
            $t->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
