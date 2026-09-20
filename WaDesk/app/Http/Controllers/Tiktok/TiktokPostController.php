<?php

namespace App\Http\Controllers\Tiktok;

use App\Http\Controllers\Controller;
use App\Models\TiktokAccount;
use App\Models\TiktokPost;
use App\Services\Tiktok\TiktokClient;
use App\Services\Tiktok\TiktokTokenRefreshSweeper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * TikTok posting (Content Posting API — Upload/Inbox path). The composer sends a
 * video to the creator's TikTok inbox/drafts via PULL_FROM_URL; the creator
 * finishes the post inside the TikTok app. This path needs only the
 * video.upload scope (no full app audit — Direct Post is a later, audited flip).
 *
 * Three surfaces mirror the Facebook posts split:
 *   /tiktok/posts          — table of posts sent from this system
 *   /tiktok/posts/create   — composer
 *   /tiktok/insights       — the live "my videos" grid (Display API)
 */
class TiktokPostController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Table of posts sent from WaDesk. */
    public function index(Request $request): View
    {
        $wsId = $this->wsId();
        try { TiktokTokenRefreshSweeper::run($wsId); } catch (\Throwable $e) {}

        $accounts  = TiktokAccount::forWorkspace($wsId)->connected()->orderBy('display_name')->get();
        $accountId = (int) $request->query('account');

        $posts = TiktokPost::forWorkspace($wsId)->with('account')
            ->when($accountId, fn ($q) => $q->where('tiktok_account_id', $accountId))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('user.tiktok.posts', compact('accounts', 'posts', 'accountId'));
    }

    /** Composer. */
    public function create(): View
    {
        $wsId     = $this->wsId();
        $accounts = TiktokAccount::forWorkspace($wsId)->connected()->orderBy('display_name')->get();

        // Best-effort creator info for the first account (privacy options, max
        // duration) — drives the composer hints. Optional for the inbox path.
        $creator = [];
        if ($accounts->isNotEmpty()) {
            try { $creator = (new TiktokClient($accounts->first()))->queryCreatorInfo(); } catch (\Throwable $e) {}
        }

        return view('user.tiktok.create', compact('accounts', 'creator'));
    }

    /** True only when the site URL is a public HTTPS host (TikTok must fetch uploads). */
    private function publicHost(): bool
    {
        $url = (string) config('app.url');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '' || (string) (parse_url($url, PHP_URL_SCHEME) ?: '') !== 'https') {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return false;
        }

        return ! str_ends_with($host, '.local') && ! str_ends_with($host, '.test');
    }

    /**
     * Send a video OR photo post to the creator's TikTok inbox/drafts. Accepts an
     * UPLOADED file (stored to public storage, then fetched by TikTok via
     * PULL_FROM_URL) OR a pasted public URL. The creator finishes the post in the
     * TikTok app (no audit needed for the inbox path).
     */
    public function store(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'tiktok_account_id' => 'required|integer',
            'post_type'         => 'nullable|in:video,photo',
            'caption'           => 'nullable|string|max:2200',
            'video'             => 'nullable|file|mimetypes:video/mp4,video/quicktime|max:307200', // 300MB
            'video_url'         => 'nullable|url|max:2048',
            'photos'            => 'nullable|array|max:35',
            'photos.*'          => 'image|max:20480', // 20MB each
            'photo_urls'        => 'nullable|string|max:8000',
        ]);

        $account = TiktokAccount::forWorkspace($wsId)->connected()->whereKey($data['tiktok_account_id'])->first();
        if (! $account) {
            return back()->withErrors(['tiktok' => __('Pick a connected TikTok account.')])->withInput();
        }

        $caption = trim((string) ($data['caption'] ?? '')) ?: null;
        $postType = (string) ($data['post_type'] ?? 'video');

        // Oversize upload → PHP drops the file; surface a real error.
        if (($rv = $request->file('video')) && ! $rv->isValid()) {
            return back()->withErrors(['tiktok' => __('That video could not be uploaded — it may exceed the server upload limit. Raise upload_max_filesize / post_max_size, or paste a public HTTPS video URL.')])->withInput();
        }

        // ── PHOTO post ──
        if ($postType === 'photo' || (! $request->hasFile('video') && empty($data['video_url']) && ($request->hasFile('photos') || ! empty($data['photo_urls'])))) {
            $photoUrls = [];
            $hasUploads = false;
            foreach ((array) $request->file('photos', []) as $file) {
                $photoUrls[] = url(Storage::url($file->store('tiktok/'.$wsId, 'public')));
                $hasUploads = true;
            }
            foreach (preg_split('/[\s,]+/', (string) ($data['photo_urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $u) {
                if (filter_var($u, FILTER_VALIDATE_URL)) {
                    $photoUrls[] = $u;
                }
            }
            $photoUrls = array_slice($photoUrls, 0, 35);
            if (empty($photoUrls)) {
                return back()->withErrors(['tiktok' => __('Add at least one photo (upload a file or paste an image URL).')])->withInput();
            }
            if ($hasUploads && ! $this->publicHost()) {
                return back()->withErrors(['tiktok' => __('TikTok fetches uploaded photos over a public HTTPS address, but this site URL is not publicly reachable. Publish from your live domain, or paste public image URLs.')])->withInput();
            }

            $post = TiktokPost::create([
                'workspace_id' => $wsId, 'tiktok_account_id' => $account->id, 'user_id' => Auth::id(),
                'type' => 'photo', 'status' => 'processing', 'caption' => $caption,
                'media_json' => ['photos' => $photoUrls, 'target' => 'inbox'],
            ]);
            $res = (new TiktokClient($account))->initInboxPhoto($photoUrls, (string) $caption);

            return $this->finishInit($post, $res);
        }

        // ── VIDEO post (uploaded file OR URL) ──
        $videoUrl = '';
        if ($request->hasFile('video')) {
            if (! $this->publicHost()) {
                return back()->withErrors(['tiktok' => __('TikTok fetches the uploaded video over a public HTTPS address, but this site URL is not publicly reachable. Publish from your live domain, or paste a public video URL.')])->withInput();
            }
            $videoUrl = url(Storage::url($request->file('video')->store('tiktok/'.$wsId, 'public')));
        } elseif (! empty($data['video_url'])) {
            if (! str_starts_with(strtolower((string) $data['video_url']), 'https://')) {
                return back()->withErrors(['tiktok' => __('The video URL must be a public HTTPS link whose domain is verified in your TikTok developer app.')])->withInput();
            }
            $videoUrl = (string) $data['video_url'];
        } else {
            return back()->withErrors(['tiktok' => __('Upload a video, paste a video URL, or switch to a photo post.')])->withInput();
        }

        $post = TiktokPost::create([
            'workspace_id' => $wsId, 'tiktok_account_id' => $account->id, 'user_id' => Auth::id(),
            'type' => 'video', 'status' => 'processing', 'caption' => $caption,
            'media_json' => ['video_url' => $videoUrl, 'target' => 'inbox'],
        ]);
        $res = (new TiktokClient($account))->initInboxVideo($videoUrl);

        return $this->finishInit($post, $res);
    }

    /** Shared post-init handling for video + photo. */
    private function finishInit(TiktokPost $post, array $res): RedirectResponse
    {
        if (! empty($res['ok'])) {
            $post->forceFill(['publish_id' => $res['publish_id'], 'status' => 'processing', 'error' => null])->save();

            return redirect()->route('user.tiktok.posts')->with('status',
                __('Sent to your TikTok inbox — open the TikTok app to review and post it.'));
        }

        $post->forceFill(['status' => 'failed', 'error' => mb_substr((string) ($res['error'] ?? 'init failed'), 0, 990)])->save();

        return back()->withErrors(['tiktok' => __('TikTok rejected the upload: ').($res['error'] ?? 'unknown')])->withInput();
    }

    /** Poll TikTok for the latest status of a post (manual refresh). */
    public function status(int $id): RedirectResponse
    {
        $post = TiktokPost::forWorkspace($this->wsId())->with('account')->whereKey($id)->first();
        if (! $post || ! $post->account || ! $post->publish_id) {
            return back()->withErrors(['tiktok' => __('Nothing to check for this post.')]);
        }
        $d = (new TiktokClient($post->account))->fetchPublishStatus((string) $post->publish_id);
        $s = (string) ($d['status'] ?? '');
        if ($s !== '') {
            $map = [
                'PUBLISH_COMPLETE'    => 'published',
                'SEND_TO_USER_INBOX'  => 'processing',
                'PROCESSING_UPLOAD'   => 'processing',
                'PROCESSING_DOWNLOAD' => 'processing',
                'FAILED'              => 'failed',
            ];
            $post->forceFill([
                'status'         => $map[$s] ?? $post->status,
                'tiktok_post_id' => (string) (data_get($d, 'publicaly_available_post_id.0') ?: $post->tiktok_post_id),
                'published_at'   => $s === 'PUBLISH_COMPLETE' ? now() : $post->published_at,
                'error'          => $s === 'FAILED' ? (string) ($d['fail_reason'] ?? 'failed') : null,
            ])->save();
        }

        return back()->with('status', __('Status updated: :s', ['s' => $s ?: __('unknown')]));
    }

    public function destroy(int $id): RedirectResponse
    {
        $post = TiktokPost::forWorkspace($this->wsId())->whereKey($id)->first();
        if ($post) {
            $post->delete();
        }

        return back()->with('status', __('Post removed.'));
    }
}
