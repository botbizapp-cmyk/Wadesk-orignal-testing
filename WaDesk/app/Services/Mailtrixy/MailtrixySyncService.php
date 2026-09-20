<?php

namespace App\Services\Mailtrixy;

use App\Models\WorkspaceEmailAccount;
use Illuminate\Support\Facades\Log;

/**
 * Pull mail from MailTrixy into the WaDesk inbox.
 *
 * WHY THIS EXISTS
 * ---------------
 * The bridge started out push-only: MailTrixy announces each message as it
 * arrives (MessageReceived -> WaDeskPushService -> POST /api/mailtrixy/inbound).
 * That has two holes:
 *
 *   1. NO HISTORY. Mail that already existed when a mailbox was linked is never
 *      announced, so linking a busy mailbox produced an empty WaDesk inbox.
 *   2. LOSSY. The push runs inline in the mail-sync hot path with an 8s timeout
 *      and no retry, so a slow moment drops that message permanently
 *      (observed: "cURL error 28 ... after 8013ms", 9 of 9 lost).
 *
 * Pulling fixes both. Every pulled message is fed through MailtrixyIngestService
 * — the SAME code a live push lands in — and that service dedupes on the
 * MailTrixy message id, so re-syncing is safe and never duplicates a thread.
 *
 * The push stays as-is: it is the low-latency path. This is the backfill on
 * link, and the repair sweep afterwards.
 */
class MailtrixySyncService
{
    /** Page size per bridge call. */
    private const PAGE = 50;

    /** Hard stop so one mailbox can never spin forever on a huge history. */
    private const MAX_PAGES = 40;

    /**
     * Sync one linked mailbox.
     *
     * @param  bool  $full  true = from the beginning (initial backfill),
     *                      false = resume from the last synced message id.
     * @return array{ok:bool, imported:int, seen:int, pages:int, error:?string}
     */
    public static function syncAccount(WorkspaceEmailAccount $acct, bool $full = false, ?string $ownerEmail = null): array
    {
        $client = MailtrixyClient::fromSettings();
        if (! $client->isConfigured()) {
            return ['ok' => false, 'imported' => 0, 'seen' => 0, 'pages' => 0, 'error' => 'not_configured'];
        }

        // Resume cursor. Stored per mailbox so a repeat sync only asks for what
        // it has not already pulled; a full sync deliberately ignores it.
        $after = $full ? null : ($acct->mtx_last_message_id ? (int) $acct->mtx_last_message_id : null);

        $imported = $seen = $pages = 0;
        $lastId   = $after;
        $error    = null;

        for ($p = 0; $p < self::MAX_PAGES; $p++) {
            $res = $client->messages((int) $acct->mailtrixy_account_id, $ownerEmail, $lastId, self::PAGE);
            $pages++;

            if (! ($res['ok'] ?? false)) {
                $error = $res['error'] ?? 'pull_failed';
                Log::warning('[MAILTRIXY-SYNC] pull failed', [
                    'mirror' => $acct->id, 'mtx_account' => $acct->mailtrixy_account_id, 'error' => $error,
                ]);
                break;
            }

            $batch = $res['messages'] ?? [];
            if (! $batch) {
                break;   // drained
            }

            foreach ($batch as $payload) {
                $seen++;
                try {
                    // Same ingest as a live push — dedupes on the MailTrixy
                    // message id, so an already-pushed message is a no-op.
                    $before = $payload['message']['id'] ?? null;
                    $msg    = MailtrixyIngestService::ingest($acct, $payload);
                    if ($msg && $msg->wasRecentlyCreated) {
                        $imported++;
                    }
                    if ($before !== null) {
                        $lastId = max((int) $lastId, (int) $before);
                    }
                } catch (\Throwable $e) {
                    // One bad message must not abort the whole sweep.
                    Log::warning('[MAILTRIXY-SYNC] ingest failed for one message', [
                        'mirror' => $acct->id,
                        'mtx_message' => $payload['message']['id'] ?? null,
                        'error' => mb_substr($e->getMessage(), 0, 150),
                    ]);
                }
            }

            $next = $res['next_after_id'] ?? null;
            if ($next === null) {
                break;   // last page
            }
            $lastId = (int) $next;
        }

        // Persist the cursor even on a partial run, so the next sweep resumes
        // instead of re-walking everything already imported.
        if ($lastId !== null) {
            $acct->forceFill([
                'mtx_last_message_id' => (int) $lastId,
                'synced_at'           => now(),
            ])->save();
        }

        Log::info('[MAILTRIXY-SYNC] mailbox synced', [
            'mirror' => $acct->id, 'imported' => $imported, 'seen' => $seen, 'pages' => $pages,
        ]);

        return ['ok' => $error === null, 'imported' => $imported, 'seen' => $seen, 'pages' => $pages, 'error' => $error];
    }

    /**
     * Sync every linked mailbox in a workspace. Used by the "Sync" action and
     * by the periodic repair sweep.
     *
     * @return array{ok:bool, imported:int, accounts:int, errors:array<int,string>}
     */
    public static function syncWorkspace(int $workspaceId, bool $full = false, ?string $ownerEmail = null): array
    {
        $imported = 0; $errors = []; $n = 0;

        foreach (WorkspaceEmailAccount::forWorkspace($workspaceId)->connected()->get() as $acct) {
            $n++;
            $r = self::syncAccount($acct, $full, $ownerEmail);
            $imported += (int) $r['imported'];
            if (! $r['ok'] && $r['error']) {
                $errors[(int) $acct->id] = (string) $r['error'];
            }
        }

        return ['ok' => empty($errors), 'imported' => $imported, 'accounts' => $n, 'errors' => $errors];
    }
}
