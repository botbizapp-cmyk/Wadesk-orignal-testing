<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's link to an email account that lives on the connected MailTrixy
 * install. This is a MIRROR only — the real account (mailbox credentials,
 * sync, SMTP) stays on MailTrixy. WaDesk keeps the MailTrixy account id plus a
 * cached snapshot so /devices can render it as a channel and inbound pushes
 * can resolve their WaDesk workspace (email threads key raw_jid
 * 'email:<mirrorRowId>:<mtxConversationId>').
 */
class WorkspaceEmailAccount extends Model
{
    protected $fillable = [
        'workspace_id',
        'mailtrixy_account_id',
        'mtx_workspace_id',
        // Pull cursor — highest MailTrixy message id already imported for this
        // mailbox, so a repeat sync fetches only what is new.
        'mtx_last_message_id',
        'email',
        'name',
        'provider',
        'status',
        'synced_at',
    ];

    protected $casts = [
        'mailtrixy_account_id' => 'integer',
        'mtx_workspace_id'     => 'integer',
        'mtx_last_message_id'  => 'integer',
        'synced_at'            => 'datetime',
    ];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    /** Only rows still marked connected — the inbound resolver and the
     *  channel-visibility check both require an explicit 'connected'. */
    public function scopeConnected($q)
    {
        return $q->where('status', 'connected');
    }

    /**
     * The single source of truth for "does this workspace have a live email
     * channel?". Email disappears the moment EITHER the admin cuts the
     * MailTrixy connection at /admin/extensions (mailtrixy_connected off) OR
     * the workspace's last email account is unlinked on /devices. Called on
     * every inbox render, so the row check is memoised per request.
     */
    public static function hasConnected(?int $workspaceId): bool
    {
        if (!$workspaceId) return false;
        if (!\App\Services\Mailtrixy\MailtrixyClient::fromSettings()->isConnected()) {
            return false;
        }
        static $cache = [];
        if (! array_key_exists($workspaceId, $cache)) {
            $cache[$workspaceId] = static::query()->forWorkspace($workspaceId)->connected()->exists();
        }

        return $cache[$workspaceId];
    }

    /**
     * The mirror row that carried a thread — the mailbox whose MailTrixy
     * account must send the reply. Parses the mirror row id from the
     * conversation's raw_jid ('email:<mirrorRowId>:<mtxConversationId>');
     * returns null for non-email jids or when the row has been unlinked
     * (caller surfaces it).
     */
    public static function forConversation(object $conversation): ?self
    {
        $wsId = (int) ($conversation->workspace_id ?? 0);
        if ($wsId <= 0) {
            return null;
        }
        $parts = explode(':', (string) ($conversation->raw_jid ?? ''));
        if (count($parts) < 3 || $parts[0] !== 'email' || ! ctype_digit((string) $parts[1])) {
            return null;
        }

        return static::query()->where('workspace_id', $wsId)->where('id', (int) $parts[1])->first();
    }
}
