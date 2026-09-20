<?php

namespace App\Services\Social;

use App\Models\FacebookPost;
use App\Models\TiktokPost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes the three per-channel post sources — Instagram (raw
 * instagram_scheduled_posts table), Facebook (FacebookPost model), TikTok
 * (TiktokPost model) — into ONE list so the unified "All Posts" page and the
 * scheduling Calendar can render every channel side by side. Each channel
 * stores its posts differently (caption vs message, scheduled_at vs
 * scheduled_publish_time); this is the single place that reconciles them.
 */
class SocialPostAggregator
{
    public const CHANNELS = ['instagram', 'facebook', 'tiktok'];

    /**
     * @param  array{statuses?:array,channels?:array,from?:Carbon,to?:Carbon,scheduled_only?:bool,limit?:int}  $opts
     */
    public function collect(int $workspaceId, array $opts = []): Collection
    {
        $channels = $opts['channels'] ?? self::CHANNELS;
        $out = collect();

        if (in_array('instagram', $channels, true) && \Schema::hasTable('instagram_scheduled_posts')) {
            $out = $out->merge($this->instagram($workspaceId, $opts));
        }
        if (in_array('facebook', $channels, true) && \Schema::hasTable('facebook_posts')) {
            $out = $out->merge($this->facebook($workspaceId, $opts));
        }
        if (in_array('tiktok', $channels, true) && \Schema::hasTable('tiktok_posts')) {
            $out = $out->merge($this->tiktok($workspaceId, $opts));
        }

        // Status filter (normalized).
        if (! empty($opts['statuses'])) {
            $out = $out->whereIn('status', $opts['statuses']);
        }
        // Scheduled-only (calendar) + date window on scheduled_at.
        if (! empty($opts['scheduled_only'])) {
            $out = $out->filter(fn ($p) => $p['scheduled_at'] !== null);
        }
        if (! empty($opts['from']) || ! empty($opts['to'])) {
            $from = $opts['from'] ?? null;
            $to   = $opts['to'] ?? null;
            $out = $out->filter(function ($p) use ($from, $to) {
                $at = $p['scheduled_at'] ?? $p['sort_at'];
                if (! $at) {
                    return false;
                }

                return (! $from || $at->gte($from)) && (! $to || $at->lte($to));
            });
        }

        $out = $out->sortByDesc(fn ($p) => optional($p['sort_at'])->timestamp ?? 0)->values();
        if (! empty($opts['limit'])) {
            $out = $out->take($opts['limit'])->values();
        }

        return $out;
    }

    /** Status → color bucket for the UI badge. */
    public static function statusStyle(string $status): array
    {
        return match ($status) {
            'published' => ['bg-wa-green/15 text-wa-deep', 'Published'],
            'scheduled' => ['bg-[#E7F0FF] text-[#1B4B91]', 'Scheduled'],
            'failed'    => ['bg-accent-coral/10 text-accent-coral', 'Failed'],
            'processing'=> ['bg-accent-amber/20 text-[#7B5A14]', 'Processing'],
            default     => ['bg-paper-100 text-ink-600', 'Draft'],
        };
    }

    // ── per-channel normalizers ─────────────────────────────────────────────

    private function instagram(int $ws, array $opts): Collection
    {
        $rows = DB::table('instagram_scheduled_posts as p')
            ->leftJoin('instagram_accounts as a', 'a.id', '=', 'p.instagram_account_id')
            ->where('p.workspace_id', $ws)
            ->select('p.*', 'a.username as acc_username', 'a.name as acc_name', 'a.profile_pic_url as acc_avatar')
            ->orderByDesc('p.id')->limit(400)->get();

        return $rows->map(function ($r) {
            $media = $this->firstMedia([$r->image_url ?? null, $r->video_url ?? null], $r->media_urls ?? null);

            return $this->row(
                channel: 'instagram', id: $r->id,
                status: $this->normStatus((string) $r->status),
                text: (string) ($r->caption ?? ''),
                mediaUrl: $media, mediaType: (string) ($r->media_type ?? 'image'),
                scheduledAt: $this->carbon($r->scheduled_at ?? null),
                publishedAt: $this->carbon($r->published_at ?? $r->updated_at ?? null),
                createdAt: $this->carbon($r->created_at ?? null),
                error: (string) ($r->last_error ?? ''),
                accName: (string) ($r->acc_username ?: $r->acc_name ?: 'Instagram'),
                accAvatar: $r->acc_avatar ?: null,
            );
        });
    }

    private function facebook(int $ws, array $opts): Collection
    {
        return FacebookPost::forWorkspace($ws)->with('page')->orderByDesc('id')->limit(400)->get()
            ->map(function (FacebookPost $p) {
                $m = is_array($p->media_json) ? $p->media_json : [];
                $media = $this->firstMedia([$m['video'] ?? null], $m['photos'] ?? null);

                return $this->row(
                    channel: 'facebook', id: $p->id,
                    status: $this->normStatus((string) $p->status),
                    text: (string) ($p->message ?? ''),
                    mediaUrl: $media, mediaType: (string) ($p->type ?? 'text'),
                    scheduledAt: $this->carbon($p->scheduled_publish_time),
                    publishedAt: $this->carbon($p->published_at),
                    createdAt: $this->carbon($p->created_at),
                    error: (string) ($p->error ?? ''),
                    accName: (string) (optional($p->page)->name ?: 'Facebook Page'),
                    accAvatar: null,
                );
            });
    }

    private function tiktok(int $ws, array $opts): Collection
    {
        return TiktokPost::forWorkspace($ws)->with('account')->orderByDesc('id')->limit(400)->get()
            ->map(function (TiktokPost $p) {
                $m = is_array($p->media_json) ? $p->media_json : [];
                $media = $this->firstMedia([$m['video_url'] ?? null], $m['photos'] ?? null);

                return $this->row(
                    channel: 'tiktok', id: $p->id,
                    status: $this->normStatus((string) $p->status),
                    text: (string) ($p->caption ?? ''),
                    mediaUrl: $media, mediaType: (string) ($p->type ?? 'video'),
                    scheduledAt: $this->carbon($p->scheduled_at),
                    publishedAt: $this->carbon($p->published_at),
                    createdAt: $this->carbon($p->created_at),
                    error: (string) ($p->error ?? ''),
                    accName: (string) (optional($p->account)->username ?: optional($p->account)->display_name ?: 'TikTok'),
                    accAvatar: null,
                );
            });
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function row(string $channel, int $id, string $status, string $text, ?string $mediaUrl, string $mediaType, ?Carbon $scheduledAt, ?Carbon $publishedAt, ?Carbon $createdAt, string $error, string $accName, ?string $accAvatar): array
    {
        return [
            'uid'          => $channel.':'.$id,
            'channel'      => $channel,
            'channel_label'=> ucfirst($channel),
            'id'           => $id,
            'status'       => $status,
            'text'         => $text,
            'media_url'    => $mediaUrl,
            'media_type'   => $mediaType,
            'scheduled_at' => $scheduledAt,
            'published_at' => $publishedAt,
            'error'        => $error,
            'account_name' => $accName,
            'account_avatar' => $accAvatar,
            'sort_at'      => $scheduledAt ?: ($publishedAt ?: $createdAt),
        ];
    }

    private function normStatus(string $s): string
    {
        $s = strtolower(trim($s));

        return match ($s) {
            'published', 'live', 'complete' => 'published',
            'scheduled', 'pending'          => 'scheduled',
            'failed', 'error'               => 'failed',
            'processing'                    => 'processing',
            default                         => 'draft',
        };
    }

    /** First non-empty media URL from scalars or a json/array of urls; resolves storage paths. */
    private function firstMedia(array $scalars, $jsonUrls): ?string
    {
        foreach ($scalars as $u) {
            if (! empty($u)) {
                return $this->resolveUrl((string) $u);
            }
        }
        $arr = is_string($jsonUrls) ? (json_decode($jsonUrls, true) ?: []) : (is_array($jsonUrls) ? $jsonUrls : []);
        foreach ((array) $arr as $u) {
            $u = is_array($u) ? ($u['url'] ?? $u['image_url'] ?? '') : $u;
            if (! empty($u)) {
                return $this->resolveUrl((string) $u);
            }
        }

        return null;
    }

    private function resolveUrl(string $u): string
    {
        return preg_match('#^https?://#i', $u) ? $u : media_url($u);
    }

    private function carbon($v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        try {
            return $v instanceof Carbon ? $v : Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
