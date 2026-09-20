<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an inbox AI agent (ai_agents) to a trained /ai-training assistant
 * (ai_chat_assistants) so the inbox agent answers WITH that assistant's
 * knowledge base. Nullable — an agent with no link behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_agents', 'knowledge_assistant_id')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                $table->unsignedBigInteger('knowledge_assistant_id')->nullable()->after('model');
                $table->index('knowledge_assistant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_agents', 'knowledge_assistant_id')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                $table->dropIndex(['knowledge_assistant_id']);
                $table->dropColumn('knowledge_assistant_id');
            });
        }
    }
};
