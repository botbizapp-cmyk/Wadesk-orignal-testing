<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One logged AI CRM Copilot tool invocation. See the migration for column
 * intent. Written by AiCrmCopilotService for every tool it runs so the
 * workspace has a full audit trail of AI-driven CRM changes.
 */
class AiCrmAction extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'channel', 'tool', 'kind', 'status',
        'params', 'result_summary', 'provider', 'model', 'tokens',
        'subject_type', 'subject_id',
    ];

    protected $casts = [
        'params' => 'array',
        'tokens' => 'int',
    ];

    public function scopeForCurrentWorkspace($q)
    {
        return $q->where('workspace_id', (int) (auth()->user()->current_workspace_id ?? 0));
    }
}
