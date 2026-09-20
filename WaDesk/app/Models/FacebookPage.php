<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected Facebook Page. The user connects their Facebook account once and
 * every Page that account manages is stored here — each with its own PAGE
 * access token (encrypted at rest). One workspace can connect several Pages.
 *
 * Meta does not allow posting to a personal profile, so the Page is the
 * API-addressable unit for publishing, comments, Messenger and insights.
 */
class FacebookPage extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id',
        'page_id', 'name', 'category', 'username', 'picture_url',
        'access_token', 'token_expires_at', 'data_access_expires_at',
        'scopes', 'tasks',
        'status', 'connect_method', 'fan_count', 'last_error', 'meta_json',
    ];

    protected $casts = [
        'access_token'           => 'encrypted',
        'token_expires_at'       => 'datetime',
        'data_access_expires_at' => 'datetime',
        'scopes'                 => 'array',
        'tasks'                  => 'array',
        'meta_json'              => 'array',
    ];

    protected $hidden = ['access_token'];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeConnected($q)
    {
        return $q->where('status', 'connected');
    }

    public function isLive(): bool
    {
        return $this->status === 'connected'
            && (! $this->token_expires_at || $this->token_expires_at->isFuture());
    }

    /** True when this Page's token grants a task (e.g. CREATE_CONTENT, MESSAGING). */
    public function hasTask(string $task): bool
    {
        return in_array($task, (array) ($this->tasks ?? []), true);
    }

    /**
     * Does this workspace have at least one connected Facebook Page? The live
     * gate every inbox surface checks (mirrors WorkspaceIgAccount::hasConnected)
     * so Facebook threads only show while a Page is connected. Cached per request.
     */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = self::where('workspace_id', $workspaceId)
                ->where('status', 'connected')->exists();
        }

        return $cache[$workspaceId];
    }

    /** Resolve a connected Page by its Meta Page id (webhook entry.id). */
    public static function findByPageId(string $pageId): ?self
    {
        return self::where('page_id', $pageId)->where('status', 'connected')->first();
    }
}
