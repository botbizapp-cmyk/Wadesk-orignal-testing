<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Facebook post composed / scheduled from WaDesk for a connected Page.
 */
class FacebookPost extends Model
{
    protected $fillable = [
        'workspace_id', 'facebook_page_id', 'user_id',
        'fb_post_id', 'type', 'status',
        'message', 'link', 'media_json',
        'scheduled_publish_time', 'published_at', 'error', 'meta_json',
    ];

    protected $casts = [
        'media_json'             => 'array',
        'meta_json'              => 'array',
        'scheduled_publish_time' => 'datetime',
        'published_at'           => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class, 'facebook_page_id');
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
