<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Services\Facebook\FacebookPageClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Facebook posting — compose, schedule, publish and delete posts on a connected
 * Page. Text / link / photo / multi-photo, published now or scheduled. Uses the
 * Page's own access token via FacebookPageClient.
 */
class FacebookPostController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Is our site URL a public HTTPS host Meta can fetch uploaded images from? */
    private function publicHost(): bool
    {
        $url = (string) config('app.url');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $scheme = (string) (parse_url($url, PHP_URL_SCHEME) ?: '');
        if ($host === '' || $scheme !== 'https') {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return false;
        }

        return ! str_ends_with($host, '.local') && ! str_ends_with($host, '.test');
    }

    /**
     * Resolve a schedule timestamp from the request (user timezone) with the
     * Meta window enforced. Returns [?int unixTs, ?string errorMessage].
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function scheduledTs(Request $request, array $data): array
    {
        if (! $request->boolean('schedule') || empty($data['scheduled_at'])) {
            return [null, null];
        }
        $tz = Auth::user()?->timezone ?: config('app.timezone');
        try {
            $when = Carbon::parse((string) $data['scheduled_at'], $tz);
        } catch (\Throwable $e) {
            $when = Carbon::parse((string) $data['scheduled_at']);
        }
        if ($when->lt(now()->addMinutes(10))) {
            return [null, __('Scheduled time must be at least 10 minutes from now.')];
        }
        if ($when->gt(now()->addDays(75))) {
            return [null, __('Scheduled time cannot be more than 75 days out.')];
        }

        return [$when->timestamp, null];
    }

    /**
     * GET /facebook/posts — composer + filtered post history. Mirrors the
     * Instagram posts index: KPI cards, status/type/range/search filters and a
     * paginated list of FacebookPost rows. The 'draft' status (a post whose
     * first publish attempt did not complete) is grouped with 'failed' — both
     * are unpublished + retryable.
     */
    public function index(Request $request): View
    {
        $wsId  = $this->wsId();
        $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();

        // ── Filters (mirror /instagram/posts): status · type · range · search ──
        $status = (string) $request->query('status', 'all');
        $status = in_array($status, ['scheduled', 'published', 'failed'], true) ? $status : 'all';

        $type = (string) $request->query('type', 'all');
        $type = in_array($type, ['photo', 'multi_photo', 'link', 'video', 'reel', 'text'], true) ? $type : 'all';

        $range = (string) $request->query('range', 'all');
        $range = in_array($range, ['7d', '30d', '90d'], true) ? $range : 'all';
        $since = match ($range) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };

        $search = trim((string) $request->query('q', ''));
        // Account filter — the header Page dropdown (uses `account`, NOT `page`,
        // which the paginator owns).
        $accountId = (int) $request->query('account');

        $base = fn () => FacebookPost::forWorkspace($wsId)
            ->when($accountId, fn ($q) => $q->where('facebook_page_id', $accountId));

        // 'failed' also surfaces stuck drafts (retryable, unpublished).
        $applyStatus = function ($q) use ($status) {
            if ($status === 'failed') {
                return $q->whereIn('status', ['failed', 'draft']);
            }
            if ($status !== 'all') {
                return $q->where('status', $status);
            }

            return $q;
        };

        $posts = $applyStatus($base())
            ->with('page')
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('message', 'like', '%' . $search . '%')->orWhere('link', 'like', '%' . $search . '%')))
            ->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'failed' THEN 1 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('scheduled_publish_time')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $counts = [
            'scheduled' => $base()->where('status', 'scheduled')->count(),
            'published' => $base()->where('status', 'published')->count(),
            'failed'    => $base()->whereIn('status', ['failed', 'draft'])->count(),
        ];
        $total = $base()->count();

        $typeCounts = [
            'all'         => $total,
            'photo'       => $base()->where('type', 'photo')->count(),
            'multi_photo' => $base()->where('type', 'multi_photo')->count(),
            'link'        => $base()->where('type', 'link')->count(),
            'video'       => $base()->where('type', 'video')->count(),
            'reel'        => $base()->where('type', 'reel')->count(),
            'text'        => $base()->where('type', 'text')->count(),
        ];

        $userTz = Auth::user()?->currentWorkspace?->timezone
            ?: (Auth::user()?->timezone ?: config('app.timezone', 'UTC'));

        return view('user.facebook.posts', [
            'pages'         => $pages,
            'posts'         => $posts,
            'counts'        => $counts,
            'total'         => $total,
            'typeCounts'    => $typeCounts,
            'currentStatus' => $status,
            'currentType'   => $type,
            'currentRange'  => $range,
            'currentSearch' => $search,
            'userTz'        => $userTz,
            'aiAllowed'     => \App\Services\PlanLimitGuard::hasFeature(Auth::user()?->currentWorkspace, 'access_ai_agents'),
        ]);
    }

    /**
     * GET /facebook/posts/create — the composer (create / schedule a post) with
     * the live mobile preview. Split out from the index table so "Posts" (the
     * queue table), "Create" (this) and "My posts" (the live grid) are three
     * distinct pages, mirroring the Instagram posts flow.
     */
    public function create(Request $request): View
    {
        $wsId  = $this->wsId();
        $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();
        $userTz = Auth::user()?->currentWorkspace?->timezone
            ?: (Auth::user()?->timezone ?: config('app.timezone', 'UTC'));

        return view('user.facebook.create', [
            'pages'     => $pages,
            'userTz'    => $userTz,
            'aiAllowed' => \App\Services\PlanLimitGuard::hasFeature(Auth::user()?->currentWorkspace, 'access_ai_agents'),
        ]);
    }

    /**
     * GET /facebook/my-posts — the live Page grid (mirrors
     * InstagramPostsController::grid). Reads the Page's recently published posts
     * straight from the Graph API and shows them as a tile grid; nothing is
     * mirrored into our DB. Never 500s — every risky step is isolated and logged
     * under [FB-MYPOSTS].
     */
    public function grid(Request $request): View
    {
        $wsId = $this->wsId();

        try {
            $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();
        } catch (\Throwable $e) {
            Log::error('[FB-MYPOSTS] page query failed', ['workspace_id' => $wsId, 'error' => $e->getMessage()]);
            $pages = collect();
        }

        $wanted = (int) $request->query('page');
        $page   = ($wanted ? $pages->firstWhere('id', $wanted) : null) ?: $pages->first();

        $items = [];
        if ($page) {
            try {
                $items = (new FacebookPageClient($page))->recentPosts(50);
            } catch (\Throwable $e) {
                Log::error('[FB-MYPOSTS] recent posts fetch failed', [
                    'page_id'      => $page->id ?? null,
                    'workspace_id' => $wsId,
                    'error'        => $e->getMessage(),
                    'where'        => $e->getFile() . ':' . $e->getLine(),
                ]);
                $items = [];
            }
        }

        return view('user.facebook.grid', [
            'pages'    => $pages,
            'page'     => $page,
            'selected' => (int) ($page->id ?? 0),
            'items'    => $items,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'facebook_page_id' => 'required|integer',
            'message'          => 'nullable|string|max:60000',
            'link'             => 'nullable|url|max:2048',
            'photos'           => 'nullable|array|max:10',
            'photos.*'         => 'image|max:8192', // 8MB each
            'photo_urls'       => 'nullable|string|max:8000',
            'video'            => 'nullable|file|mimetypes:video/mp4,video/quicktime|max:204800', // 200MB
            'video_url'        => 'nullable|url|max:2048',
            'as_reel'          => 'nullable|boolean',
            'schedule'         => 'nullable|boolean',
            'scheduled_at'     => 'nullable|date',
        ]);

        $page = FacebookPage::forWorkspace($wsId)->connected()->whereKey($data['facebook_page_id'])->first();
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')])->withInput();
        }

        // ── Video / Reel takes priority when a video is provided. Meta fetches
        //    the file over public HTTPS, so uploads need a public site URL. ──
        // Oversize upload → PHP drops the file (UPLOAD_ERR_INI_SIZE); surface a
        // real error instead of silently falling through to the text path.
        $rawVideo = $request->file('video');
        if ($rawVideo && ! $rawVideo->isValid()) {
            return back()->withErrors(['facebook' => __('That video could not be uploaded — it may exceed the server upload limit. Raise upload_max_filesize / post_max_size, or paste a public HTTPS video URL.')])->withInput();
        }

        $videoUrl = '';
        if ($request->hasFile('video')) {
            if (! $this->publicHost()) {
                return back()->withErrors(['facebook' => __('Facebook needs to fetch uploaded video over a public HTTPS address, but this site URL is not publicly reachable. Publish from your live domain, or paste a public video URL instead.')])->withInput();
            }
            $videoUrl = url(Storage::url($request->file('video')->store('facebook/'.$wsId, 'public')));
        } elseif (! empty($data['video_url'])) {
            if (! str_starts_with(strtolower((string) $data['video_url']), 'https://')) {
                return back()->withErrors(['facebook' => __('The video URL must be a public HTTPS link.')])->withInput();
            }
            $videoUrl = (string) $data['video_url'];
        }

        if ($videoUrl !== '') {
            [$scheduledTs, $schedErr] = $this->scheduledTs($request, $data);
            if ($schedErr) {
                return back()->withErrors(['facebook' => $schedErr])->withInput();
            }
            $isReel = $request->boolean('as_reel');
            $desc = trim((string) ($data['message'] ?? ''));

            $post = FacebookPost::create([
                'workspace_id'     => $wsId,
                'facebook_page_id' => $page->id,
                'user_id'          => Auth::id(),
                'type'             => $isReel ? 'reel' : 'video',
                'status'           => $scheduledTs ? 'scheduled' : 'draft',
                'message'          => $desc ?: null,
                'media_json'       => ['video' => $videoUrl],
                'scheduled_publish_time' => $scheduledTs ? Carbon::createFromTimestamp($scheduledTs) : null,
            ]);

            $client = new FacebookPageClient($page);
            $res = $isReel
                ? $client->postReel($videoUrl, $desc, $scheduledTs)
                : $client->postVideo($videoUrl, $desc, $scheduledTs);

            if (! empty($res['ok'])) {
                $post->forceFill([
                    'fb_post_id'   => $res['id'] ?: null,
                    'status'       => $scheduledTs ? 'scheduled' : 'published',
                    'published_at' => $scheduledTs ? null : now(),
                    'error'        => null,
                ])->save();

                return redirect()->route('user.facebook.posts')->with('status',
                    $scheduledTs ? __('Video scheduled.') : ($isReel ? __('Reel published to Facebook.') : __('Video published to Facebook.')));
            }

            $post->forceFill(['status' => 'failed', 'error' => mb_substr((string) ($res['error'] ?? 'publish failed'), 0, 990)])->save();

            return back()->withErrors(['facebook' => __('Facebook rejected the video: ').($res['error'] ?? 'unknown')])->withInput();
        }

        // Gather image URLs: uploaded files (stored public) + pasted URLs.
        $photoUrls = [];
        $hasUploads = false;
        foreach ((array) $request->file('photos', []) as $file) {
            $path = $file->store('facebook/'.$wsId, 'public');
            $photoUrls[] = url(Storage::url($path));
            $hasUploads = true;
        }
        foreach (preg_split('/[\s,]+/', (string) ($data['photo_urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $u) {
            if (filter_var($u, FILTER_VALIDATE_URL)) {
                $photoUrls[] = $u;
            }
        }
        // Meta caps media per post — cap the combined (uploads + pasted) list.
        $photoUrls = array_slice($photoUrls, 0, 10);

        $message = trim((string) ($data['message'] ?? ''));
        $link = trim((string) ($data['link'] ?? ''));
        if ($message === '' && $link === '' && empty($photoUrls)) {
            return back()->withErrors(['facebook' => __('Write something, add a link, or attach a photo.')])->withInput();
        }
        // Meta rejects a feed post that carries BOTH a link preview and photo
        // attachments — guide the user instead of letting Graph reject it.
        if (! empty($photoUrls) && $link !== '') {
            return back()->withErrors(['facebook' => __('A post can have photos or a link, not both. Remove one.')])->withInput();
        }
        // Uploaded images are fetched by Meta from our public URL. If the site
        // URL isn't a public HTTPS host, that fetch fails — warn before trying.
        if ($hasUploads && ! $this->publicHost()) {
            return back()->withErrors(['facebook' => __('Facebook needs to fetch uploaded images over a public HTTPS address, but this site URL is not publicly reachable. Publish from your live domain, or paste public image URLs instead.')])->withInput();
        }

        // Schedule window (Meta: 10 min … ~75 days), parsed in the user's tz.
        [$scheduledTs, $schedErr] = $this->scheduledTs($request, $data);
        if ($schedErr) {
            return back()->withErrors(['facebook' => $schedErr])->withInput();
        }

        $type = ! empty($photoUrls) ? (count($photoUrls) > 1 ? 'multi_photo' : 'photo') : ($link !== '' ? 'link' : 'text');

        $post = FacebookPost::create([
            'workspace_id'     => $wsId,
            'facebook_page_id' => $page->id,
            'user_id'          => Auth::id(),
            'type'             => $type,
            'status'           => $scheduledTs ? 'scheduled' : 'draft',
            'message'          => $message ?: null,
            'link'             => $link ?: null,
            'media_json'       => ['photos' => $photoUrls],
            'scheduled_publish_time' => $scheduledTs ? Carbon::createFromTimestamp($scheduledTs) : null,
        ]);

        $client = new FacebookPageClient($page);
        $res = $client->publish([
            'message'      => $message,
            'link'         => $link,
            'photos'       => $photoUrls,
            'scheduled_ts' => $scheduledTs,
        ]);

        if (! empty($res['ok'])) {
            $post->forceFill([
                'fb_post_id'   => $res['id'] ?: null,
                'status'       => $scheduledTs ? 'scheduled' : 'published',
                'published_at' => $scheduledTs ? null : now(),
                'error'        => null,
            ])->save();

            return redirect()->route('user.facebook.posts')->with('status',
                $scheduledTs ? __('Post scheduled.') : __('Post published to Facebook.'));
        }

        $post->forceFill(['status' => 'failed', 'error' => mb_substr((string) ($res['error'] ?? 'publish failed'), 0, 990)])->save();

        return back()->withErrors(['facebook' => __('Facebook rejected the post: ').($res['error'] ?? 'unknown')])->withInput();
    }

    /** Publish a draft/failed post now (retry). */
    public function publishNow(int $id): RedirectResponse
    {
        $wsId = $this->wsId();
        $post = FacebookPost::forWorkspace($wsId)->with('page')->whereKey($id)->first();
        if (! $post || ! $post->page) {
            return back()->withErrors(['facebook' => __('Post not found.')]);
        }

        $client = new FacebookPageClient($post->page);
        if (in_array($post->type, ['video', 'reel'], true)) {
            $videoUrl = (string) data_get($post->media_json, 'video', '');
            $res = $post->type === 'reel'
                ? $client->postReel($videoUrl, (string) $post->message)
                : $client->postVideo($videoUrl, (string) $post->message);
        } else {
            $res = $client->publish([
                'message' => (string) $post->message,
                'link'    => (string) $post->link,
                'photos'  => (array) data_get($post->media_json, 'photos', []),
            ]);
        }

        if (! empty($res['ok'])) {
            $post->forceFill(['fb_post_id' => $res['id'] ?: null, 'status' => 'published', 'published_at' => now(), 'error' => null])->save();

            return back()->with('status', __('Post published to Facebook.'));
        }

        $post->forceFill(['status' => 'failed', 'error' => mb_substr((string) ($res['error'] ?? 'publish failed'), 0, 990)])->save();

        return back()->withErrors(['facebook' => __('Publish failed: ').($res['error'] ?? 'unknown')]);
    }

    /** Delete a post (from Meta if live, then locally). */
    public function destroy(int $id): RedirectResponse
    {
        $wsId = $this->wsId();
        $post = FacebookPost::forWorkspace($wsId)->with('page')->whereKey($id)->first();
        if (! $post) {
            return back()->withErrors(['facebook' => __('Post not found.')]);
        }
        if ($post->fb_post_id && $post->page) {
            try {
                (new FacebookPageClient($post->page))->deletePost((string) $post->fb_post_id);
            } catch (\Throwable $e) { /* remove locally regardless */ }
        }
        $post->delete();

        return back()->with('status', __('Post removed.'));
    }
}
