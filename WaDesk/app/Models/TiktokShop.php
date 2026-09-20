<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected TikTok Shop (App C — Partner API). One workspace can connect
 * several shops. Every API call needs the access_token + shop_cipher; tokens are
 * short-lived and rotate. Encrypted at rest.
 */
class TiktokShop extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id',
        'shop_id', 'shop_name', 'shop_cipher', 'shop_code', 'region', 'seller_name',
        'access_token', 'refresh_token', 'token_expires_at', 'refresh_expires_at',
        'status', 'last_error', 'meta_json',
    ];

    protected $casts = [
        'access_token'       => 'encrypted',
        'refresh_token'      => 'encrypted',
        'token_expires_at'   => 'datetime',
        'refresh_expires_at' => 'datetime',
        'meta_json'          => 'array',
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

    public function isLive(): bool
    {
        return $this->status === 'connected';
    }

    public static function hasConnected(int $workspaceId): bool
    {
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = self::where('workspace_id', $workspaceId)
                ->where('status', 'connected')->exists();
        }

        return $cache[$workspaceId];
    }
}
