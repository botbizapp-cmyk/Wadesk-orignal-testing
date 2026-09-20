<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp group owned by a Cloud API (WABA) number.
 *
 * NOT the same thing as `WaGroup` — that model is the Unofficial-API side,
 * keyed on a `…@g.us` jid. Here the identity is Meta's opaque `group_id`, and
 * the group belongs to a specific `wa_provider_configs` row because Cloud API
 * groups are owned by a phone number, not a workspace.
 */
class WabaGroup extends Model
{
    protected $fillable = [
        'workspace_id', 'provider_config_id', 'group_id',
        'subject', 'description', 'invite_link', 'join_approval_mode',
        'participant_count', 'suspended', 'creation_timestamp',
        'synced_at', 'meta_json',
    ];

    protected $casts = [
        'meta_json'          => 'array',
        'suspended'          => 'boolean',
        'participant_count'  => 'integer',
        'creation_timestamp' => 'datetime',
        'synced_at'          => 'datetime',
    ];

    /** Meta's two join modes. */
    public const APPROVAL_REQUIRED = 'approval_required';
    public const AUTO_APPROVE      = 'auto_approve';

    public function provider(): BelongsTo
    {
        return $this->belongsTo(WaProviderConfig::class, 'provider_config_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeForWorkspace(Builder $q, ?int $workspaceId): Builder
    {
        return $workspaceId ? $q->where('workspace_id', $workspaceId) : $q->whereRaw('1=0');
    }

    /** Groups that can actually be messaged right now. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('suspended', false);
    }

    /**
     * True when this workspace has at least one usable Cloud API group — the
     * same shape every other channel's availability gate uses, so nav and
     * pickers can hide the surface on accounts that have none.
     */
    public static function hasConnected(int $workspaceId): bool
    {
        return static::query()->forWorkspace($workspaceId)->usable()->exists();
    }

    /**
     * Meta only hands the invite link over on the group_lifecycle_update
     * webhook, so a group created seconds ago legitimately has none yet. The UI
     * needs to tell that apart from "link was reset and is gone".
     */
    public function awaitingInviteLink(): bool
    {
        return trim((string) $this->invite_link) === '';
    }
}
