<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TikTok post composed / scheduled from WaDesk for a connected account,
 * published through the Content Posting API.
 */
class TiktokPost extends Model
{
    protected $fillable = [
        'workspace_id', 'tiktok_account_id', 'user_id',
        'type', 'status', 'caption', 'media_json',
        'publish_id', 'tiktok_post_id', 'scheduled_at', 'published_at', 'error', 'meta_json',
    ];

    protected $casts = [
        'media_json'   => 'array',
        'meta_json'    => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TiktokAccount::class, 'tiktok_account_id');
    }

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeScheduled($q)
    {
        return $q->where('status', 'scheduled');
    }
}
