<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\InboxMessage;
use App\Services\Facebook\FacebookPageClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Facebook Page insights. Blends two sources so the page is never empty:
 *  1. Live Graph metrics (reach / impressions / engagement, 28-day) — shown
 *     when Meta returns them; deprecated metrics simply come back empty.
 *  2. Local WaDesk analytics (Messenger conversations, messages in/out, posts
 *     published from this system) — always available, scoped to the selected
 *     Page by its channel='facebook' conversations (raw_jid = fb:PAGE:PSID) and
 *     FacebookPost rows.
 *
 * Every Page has an always-visible identity header + account switcher, so the
 * operator can see and change which connected Page they are looking at.
 */
class FacebookInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $wsId  = (int) (Auth::user()?->current_workspace_id ?? 0);
        $userTz = Auth::user()?->timezone ?: config('app.timezone', 'UTC');
        $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();

        $page      = null;
        $identity  = [];
        $followers = null;
        $kpis      = [];
        $local     = ['convos' => 0, 'convos_open' => 0, 'msg_in' => 0, 'msg_out' => 0,
                      'posts' => 0, 'posts_published' => 0, 'posts_scheduled' => 0];
        $trend     = ['labels' => [], 'in' => [], 'out' => []];
        $contentMix = [];
        $convoMix   = [];
        $posts     = [];

        if ($pages->isNotEmpty()) {
            $pageId = (int) $request->integer('page', $pages->first()->id);
            $page   = $pages->firstWhere('id', $pageId) ?: $pages->first();

            $client  = new FacebookPageClient($page);
            $profile = $client->getProfile();

            // ── Identity (live profile → fall back to stored fields) ──
            $identity = [
                'name'     => $profile['name']     ?? $page->name ?? $page->page_id,
                'category' => $profile['category']  ?? $page->category ?? __('Facebook Page'),
                'username' => $profile['username']  ?? $page->username,
                'avatar'   => data_get($profile, 'picture.data.url') ?: $page->picture_url,
                'page_id'  => $page->page_id,
            ];
            $followers = $profile['followers_count'] ?? $profile['fan_count'] ?? $page->fan_count;

            // ── Live Graph KPIs (missing metrics skipped, no error) ──
            $data = $client->pageInsights([
                'page_impressions', 'page_impressions_unique',
                'page_post_engagements', 'page_views_total',
                'page_fan_adds',
            ]);
            foreach ($data as $m) {
                $name   = (string) ($m['name'] ?? '');
                $values = (array) ($m['values'] ?? []);
                $last   = end($values);
                if ($name !== '') {
                    $kpis[$name] = (int) ($last['value'] ?? 0);
                }
            }

            // ── Local WaDesk analytics (always available) ──
            $jidPrefix = 'fb:' . $page->page_id . ':';
            $convoQ = Conversation::query()
                ->where('workspace_id', $wsId)
                ->where('channel', 'facebook')
                ->where('raw_jid', 'like', $jidPrefix . '%');
            $convoIds = (clone $convoQ)->pluck('id');

            $local['convos']      = $convoIds->count();
            $local['convos_open'] = (clone $convoQ)->whereIn('inbox_status', ['open', 'pending'])->count();

            if ($convoIds->isNotEmpty()) {
                $since28 = Carbon::now()->subDays(28);
                $local['msg_in']  = InboxMessage::whereIn('conversation_id', $convoIds)
                    ->where('direction', 'in')->where('created_at', '>=', $since28)->count();
                $local['msg_out'] = InboxMessage::whereIn('conversation_id', $convoIds)
                    ->where('direction', 'out')->where('created_at', '>=', $since28)->count();

                // 14-day in/out message trend
                $days = 14;
                $start = Carbon::now($userTz)->startOfDay()->subDays($days - 1);
                $rows = InboxMessage::whereIn('conversation_id', $convoIds)
                    ->where('created_at', '>=', $start->copy()->timezone(config('app.timezone', 'UTC')))
                    ->selectRaw('DATE(created_at) as d, direction, COUNT(*) as c')
                    ->groupBy('d', 'direction')->get();
                $byDay = [];
                foreach ($rows as $r) {
                    $byDay[(string) $r->d][$r->direction] = (int) $r->c;
                }
                for ($i = 0; $i < $days; $i++) {
                    $day = $start->copy()->addDays($i);
                    $key = $day->format('Y-m-d');
                    $trend['labels'][] = $day->format('M j');
                    $trend['in'][]  = (int) ($byDay[$key]['in'] ?? 0);
                    $trend['out'][] = (int) ($byDay[$key]['out'] ?? 0);
                }
            } else {
                // Empty trend skeleton so the chart axis still renders.
                for ($i = 13; $i >= 0; $i--) {
                    $trend['labels'][] = Carbon::now($userTz)->subDays($i)->format('M j');
                    $trend['in'][] = 0;
                    $trend['out'][] = 0;
                }
            }

            // Conversation status mix (open / pending / resolved / closed)
            $convoMix = (clone $convoQ)
                ->selectRaw('COALESCE(inbox_status, "open") as s, COUNT(*) as c')
                ->groupBy('s')->pluck('c', 's')->toArray();

            // ── Posts from this system ──
            $postQ = FacebookPost::query()->where('facebook_page_id', $page->id);
            $local['posts']           = (clone $postQ)->count();
            $local['posts_published'] = (clone $postQ)->where('status', 'published')->count();
            $local['posts_scheduled'] = (clone $postQ)->where('status', 'scheduled')->count();
            $contentMix = (clone $postQ)
                ->selectRaw('COALESCE(type, "photo") as t, COUNT(*) as c')
                ->groupBy('t')->pluck('c', 't')->toArray();

            // ── Recent posts performance: live Graph first, local fallback ──
            foreach ($client->recentPosts(6) as $p) {
                $pid = (string) ($p['id'] ?? '');
                $ins = $pid !== '' ? $client->postInsights($pid, ['post_impressions', 'post_engaged_users']) : [];
                $row = ['impressions' => 0, 'engaged' => 0];
                foreach ($ins as $mi) {
                    $vals = (array) ($mi['values'] ?? []);
                    $v = (int) (($vals[0]['value'] ?? 0));
                    if (($mi['name'] ?? '') === 'post_impressions') {
                        $row['impressions'] = $v;
                    } elseif (($mi['name'] ?? '') === 'post_engaged_users') {
                        $row['engaged'] = $v;
                    }
                }
                $posts[] = [
                    'message'   => (string) ($p['message'] ?? ''),
                    'created'   => (string) ($p['created_time'] ?? ''),
                    'permalink' => (string) ($p['permalink_url'] ?? ''),
                    'picture'   => (string) ($p['full_picture'] ?? ''),
                    'type'      => 'live',
                    'stats'     => $row,
                ];
            }
            if (empty($posts)) {
                foreach ((clone $postQ)->latest('id')->limit(6)->get() as $p) {
                    $media = (array) ($p->media_json ?? []);
                    $posts[] = [
                        'message'   => (string) $p->message,
                        'created'   => optional($p->published_at ?? $p->created_at)->toIso8601String() ?? '',
                        'permalink' => $p->fb_post_id ? 'https://facebook.com/' . $p->fb_post_id : '',
                        'picture'   => (string) ($media[0]['url'] ?? $media['url'] ?? ''),
                        'type'      => (string) ($p->type ?: 'photo'),
                        'status'    => (string) $p->status,
                        'stats'     => ['impressions' => 0, 'engaged' => 0],
                    ];
                }
            }
        }

        return view('user.facebook.insights', compact(
            'pages', 'page', 'identity', 'followers', 'kpis',
            'local', 'trend', 'contentMix', 'convoMix', 'posts'
        ));
    }
}
