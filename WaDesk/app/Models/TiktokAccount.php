<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected TikTok account (Login Kit OAuth). One workspace can connect
 * several accounts; each carries its own access + refresh token (encrypted at
 * rest). Access tokens expire every ~24h and are refreshed proactively by
 * TiktokTokenRefreshSweeper — mirrors FacebookPage but with a live token
 * lifecycle instead of Meta's non-expiring Page tokens.
 */
class TiktokAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id',
        'open_id', 'union_id', 'display_name', 'username', 'avatar_url', 'bio',
        'is_verified', 'follower_count', 'following_count', 'likes_count', 'video_count',
        'access_token', 'refresh_token', 'token_expires_at', 'refresh_expires_at', 'scopes',
        'status', 'connect_method', 'last_error', 'meta_json',
    ];

    protected $casts = [
        'access_token'       => 'encrypted',
        'refresh_token'      => 'encrypted',
        'token_expires_at'   => 'datetime',
        'refresh_expires_at' => 'datetime',
        'scopes'             => 'array',
        'meta_json'          => 'array',
        'is_verified'        => 'boolean',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeConnected($q)
    {
        return $q->where('status', 'connected');
    }

    /** True while the account is connected and its access token has not lapsed. */
    public function isLive(): bool
    {
        return $this->status === 'connected'
            && (! $this->token_expires_at || $this->token_expires_at->isFuture());
    }

    /** Does this workspace have at least one connected TikTok account? */
    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = self::where('workspace_id', $workspaceId)
                ->where('status', 'connected')->exists();
        }

        return $cache[$workspaceId];
    }

    public static function findByOpenId(string $openId): ?self
    {
        return self::where('open_id', $openId)->where('status', 'connected')->first();
    }
}
