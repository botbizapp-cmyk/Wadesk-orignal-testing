<?php

namespace App\Http\Controllers;

use App\Services\Social\SocialPostAggregator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Unified cross-channel social publishing surface — ONE "All Posts" list and a
 * scheduling Calendar spanning Instagram + Facebook + TikTok, via
 * {@see SocialPostAggregator}. Read-only aggregation; every action (edit /
 * publish now / cancel) links to the channel's own existing post routes so the
 * per-channel publish logic stays the single source of truth.
 */
class SocialPostsController extends Controller
{
    public function __construct(private SocialPostAggregator $agg) {}

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function tz(): string
    {
        return safe_timezone(config('app.timezone'), 'Asia/Calcutta');
    }

    public function posts(Request $request)
    {
        $wsId = $this->wsId();
        $status  = $request->query('status');   // scheduled|published|failed|draft|processing
        $channel = $request->query('platform'); // instagram|facebook|tiktok

        $all = $this->agg->collect($wsId, [
            'channels' => $channel && in_array($channel, SocialPostAggregator::CHANNELS, true) ? [$channel] : SocialPostAggregator::CHANNELS,
        ]);

        // KPI counts across ALL (pre status-filter).
        $counts = [
            'all'       => $all->count(),
            'scheduled' => $all->where('status', 'scheduled')->count(),
            'published' => $all->where('status', 'published')->count(),
            'draft'     => $all->where('status', 'draft')->count(),
            'failed'    => $all->where('status', 'failed')->count(),
        ];

        $posts = $status ? $all->where('status', $status)->values() : $all;

        return view('user.social.posts', [
            'posts'    => $posts,
            'counts'   => $counts,
            'status'   => $status,
            'channel'  => $channel,
            'channels' => $this->connectedChannels($wsId),
        ]);
    }

    /**
     * JSON snapshot of the (filtered) posts for the live poller — the page
     * refreshes each card's status/badge/actions from this without a reload
     * (a scheduled post the sweeper published, a TikTok that finished processing).
     */
    public function postsData(Request $request)
    {
        $wsId    = $this->wsId();
        $status  = $request->query('status');
        $channel = $request->query('platform');
        $tz      = $this->tz();

        $all = $this->agg->collect($wsId, [
            'channels' => $channel && in_array($channel, SocialPostAggregator::CHANNELS, true) ? [$channel] : SocialPostAggregator::CHANNELS,
        ]);
        $posts = $status ? $all->where('status', $status)->values() : $all;

        return response()->json([
            'counts' => [
                'all'       => $all->count(),
                'scheduled' => $all->where('status', 'scheduled')->count(),
                'published' => $all->where('status', 'published')->count(),
                'draft'     => $all->where('status', 'draft')->count(),
                'failed'    => $all->where('status', 'failed')->count(),
            ],
            'posts' => $posts->map(function ($p) use ($tz) {
                [$cls, $lbl] = SocialPostAggregator::statusStyle($p['status']);
                $when = $p['scheduled_at'] ?: $p['published_at'];

                return [
                    'uid'          => $p['uid'],
                    'status'       => $p['status'],
                    'status_label' => $lbl,
                    'status_class' => $cls,
                    'when_label'   => $when ? $when->timezone($tz)->format('j M Y, g:i A') : null,
                    'error'        => $p['status'] === 'failed' ? \Illuminate\Support\Str::limit((string) $p['error'], 120) : null,
                ];
            })->values(),
        ]);
    }

    public function calendar(Request $request)
    {
        $wsId = $this->wsId();
        $tz   = $this->tz();
        $channel = $request->query('platform');
        $anchor = $request->query('date') ? Carbon::parse($request->query('date'), $tz) : Carbon::now($tz);

        // Month grid: Sun→Sat, full weeks covering the month.
        $gridStart = $anchor->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY);
        $gridEnd   = $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $posts = $this->agg->collect($wsId, [
            'channels'       => $channel && in_array($channel, SocialPostAggregator::CHANNELS, true) ? [$channel] : SocialPostAggregator::CHANNELS,
            'scheduled_only' => true,
            'from'           => $gridStart->copy()->utc(),
            'to'             => $gridEnd->copy()->utc(),
        ]);

        // Group by local Y-m-d for placement in the grid.
        $byDay = $posts->groupBy(fn ($p) => $p['scheduled_at']->copy()->timezone($tz)->format('Y-m-d'));

        // Build the day cells.
        $cells = [];
        for ($d = $gridStart->copy(); $d->lte($gridEnd); $d->addDay()) {
            $key = $d->format('Y-m-d');
            $cells[] = [
                'date'      => $d->copy(),
                'in_month'  => $d->month === $anchor->month,
                'is_today'  => $d->isToday(),
                'key'       => $key,
                'posts'     => $byDay->get($key, collect()),
            ];
        }

        // All scheduled (for the "upcoming" list) regardless of grid window.
        $upcoming = $this->agg->collect($wsId, ['statuses' => ['scheduled'], 'scheduled_only' => true])
            ->sortBy(fn ($p) => $p['scheduled_at']->timestamp)->take(12)->values();

        return view('user.social.calendar', [
            'weeks'    => array_chunk($cells, 7),
            'anchor'   => $anchor,
            'prev'     => $anchor->copy()->subMonth()->format('Y-m-d'),
            'next'     => $anchor->copy()->addMonth()->format('Y-m-d'),
            'today'    => Carbon::now($tz)->format('Y-m-d'),
            'tz'       => $tz,
            'channel'  => $channel,
            'channels' => $this->connectedChannels($wsId),
            'accounts' => $this->accounts($wsId),
            'upcoming' => $upcoming,
            'monthCount' => $posts->count(),
        ]);
    }

    /**
     * Schedule (or publish-now) a post from the calendar drawer into the chosen
     * channel's own table. Media is stored to media_storage → public URL (every
     * channel needs a fetchable https link). Instagram rows are picked up by its
     * inline ScheduledPostSweeper; Facebook/TikTok rows land as scheduled for the
     * per-channel flow. Returns JSON for the AJAX drawer.
     */
    public function schedule(Request $request)
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'channel'      => 'required|in:instagram,facebook,tiktok',
            'account_id'   => 'required|integer',
            'caption'      => 'nullable|string|max:4000',
            'scheduled_at' => 'nullable|date',
            'publish_now'  => 'nullable|boolean',
            'media'        => 'nullable|file|image|max:8192',
            'media_video'  => 'nullable|file|mimetypes:video/mp4,video/quicktime|max:102400',
        ]);

        $when = ! empty($data['publish_now'])
            ? Carbon::now($this->tz())
            : ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at'], $this->tz()) : null);
        if (! $when) {
            return response()->json(['ok' => false, 'error' => 'Pick a date & time, or choose Publish now.'], 422);
        }
        $whenUtc = $when->copy()->utc();

        // Media → media_storage → public URL.
        $imageUrl = null;
        $videoUrl = null;
        if ($request->hasFile('media')) {
            $imageUrl = media_url($this->storeMedia($request->file('media'), $wsId));
        }
        if ($request->hasFile('media_video')) {
            $videoUrl = media_url($this->storeMedia($request->file('media_video'), $wsId));
        }
        if (! $imageUrl && ! $videoUrl && $data['channel'] !== 'facebook') {
            return response()->json(['ok' => false, 'error' => 'Instagram and TikTok posts need an image or video.'], 422);
        }

        try {
            $ref = match ($data['channel']) {
                'instagram' => $this->scheduleInstagram($wsId, (int) $data['account_id'], (string) ($data['caption'] ?? ''), $imageUrl, $videoUrl, $whenUtc),
                'facebook'  => $this->scheduleFacebook($wsId, (int) $data['account_id'], (string) ($data['caption'] ?? ''), $imageUrl, $videoUrl, $whenUtc),
                'tiktok'    => $this->scheduleTiktok($wsId, (int) $data['account_id'], (string) ($data['caption'] ?? ''), $videoUrl ?: $imageUrl, $whenUtc),
            };
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not schedule: '.$e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'ref' => $ref, 'scheduled_at' => $when->format('D j M, g:i A')]);
    }

    private function scheduleInstagram(int $ws, int $accId, string $caption, ?string $img, ?string $vid, Carbon $whenUtc): string
    {
        abort_unless(\App\Models\InstagramAccount::where('workspace_id', $ws)->whereKey($accId)->exists(), 422, 'Instagram account not found.');
        $id = \DB::table('instagram_scheduled_posts')->insertGetId([
            'workspace_id' => $ws, 'instagram_account_id' => $accId,
            'media_type'   => $vid ? 'reel' : 'image',
            'image_url'    => $img, 'video_url' => $vid,
            'caption'      => $caption, 'scheduled_at' => $whenUtc,
            'status'       => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'instagram:'.$id;
    }

    private function scheduleFacebook(int $ws, int $pageId, string $caption, ?string $img, ?string $vid, Carbon $whenUtc): string
    {
        $page = \App\Models\FacebookPage::forWorkspace($ws)->connected()->findOrFail($pageId);
        $post = \App\Models\FacebookPost::create([
            'workspace_id' => $ws, 'facebook_page_id' => $page->id, 'user_id' => Auth::id(),
            'type'         => $vid ? 'video' : ($img ? 'photo' : 'text'),
            'status'       => 'scheduled', 'message' => $caption,
            'media_json'   => array_filter(['photos' => $img ? [$img] : null, 'video' => $vid]),
            'scheduled_publish_time' => $whenUtc,
        ]);

        return 'facebook:'.$post->id;
    }

    private function scheduleTiktok(int $ws, int $accId, string $caption, ?string $media, Carbon $whenUtc): string
    {
        $acc = \App\Models\TiktokAccount::where('workspace_id', $ws)->findOrFail($accId);
        $post = \App\Models\TiktokPost::create([
            'workspace_id' => $ws, 'tiktok_account_id' => $acc->id, 'user_id' => Auth::id(),
            'type'         => 'video', 'status' => 'scheduled', 'caption' => $caption,
            'media_json'   => array_filter(['video_url' => $media]),
            'scheduled_at' => $whenUtc,
        ]);

        return 'tiktok:'.$post->id;
    }

    private function storeMedia(\Illuminate\Http\UploadedFile $file, int $ws): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = 'social/scheduled/'.$ws.'/'.\Illuminate\Support\Str::random(20).'.'.$ext;
        media_storage()->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /** Connected accounts across channels (for the composer drawer). */
    private function accounts(int $wsId): array
    {
        $out = [];
        if (\Schema::hasTable('instagram_accounts')) {
            foreach (\DB::table('instagram_accounts')->where('workspace_id', $wsId)->get() as $a) {
                $out[] = ['channel' => 'instagram', 'id' => $a->id, 'label' => '@'.($a->username ?: $a->name ?: $a->id), 'avatar' => $a->profile_pic_url ?? null];
            }
        }
        foreach (\App\Models\FacebookPage::forWorkspace($wsId)->connected()->get() as $p) {
            $out[] = ['channel' => 'facebook', 'id' => $p->id, 'label' => $p->name ?: ('Page '.$p->page_id), 'avatar' => null];
        }
        if (\Schema::hasTable('tiktok_accounts')) {
            foreach (\App\Models\TiktokAccount::where('workspace_id', $wsId)->get() as $a) {
                $out[] = ['channel' => 'tiktok', 'id' => $a->id, 'label' => '@'.($a->username ?: $a->display_name ?: $a->id), 'avatar' => null];
            }
        }

        return $out;
    }

    /** Which posting channels are connected (for the platform filter + New Post menu). */
    private function connectedChannels(int $wsId): array
    {
        $out = [];
        if (\Schema::hasTable('instagram_accounts') && \DB::table('instagram_accounts')->where('workspace_id', $wsId)->exists()) {
            $out[] = ['key' => 'instagram', 'label' => 'Instagram', 'create' => url('/instagram/posts/create'), 'list' => url('/instagram/posts')];
        }
        if (\App\Models\FacebookPage::forWorkspace($wsId)->connected()->exists()) {
            $out[] = ['key' => 'facebook', 'label' => 'Facebook', 'create' => url('/facebook/posts/create'), 'list' => url('/facebook/posts')];
        }
        if (\Schema::hasTable('tiktok_accounts') && \App\Models\TiktokAccount::where('workspace_id', $wsId)->exists()) {
            $out[] = ['key' => 'tiktok', 'label' => 'TikTok', 'create' => url('/tiktok/posts/create'), 'list' => url('/tiktok/posts')];
        }

        return $out;
    }
}
