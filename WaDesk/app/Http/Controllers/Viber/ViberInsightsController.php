<?php

namespace App\Http\Controllers\Viber;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\ViberChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Viber insights — engagement + read-receipt analytics computed from our own
 * inbox_messages (Viber has no analytics API). Shows 7-day inbound/outbound
 * volume, active users, and delivered/seen/failed rates from the receipt webhooks
 * dispatchViber tracks. Read-only. Mirrors the LINE/WeChat insights dashboards.
 */
class ViberInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $wsId     = $this->workspaceId();
        $channels = ViberChannel::allForWorkspace($wsId);
        $channel  = $request->integer('channel') > 0 ? $channels->firstWhere('id', $request->integer('channel')) : $channels->first();

        $data = ['channels' => $channels, 'channel' => $channel, 'rows' => [], 'totals' => [
            'in' => 0, 'out' => 0, 'users' => 0, 'delivered' => 0, 'seen' => 0, 'failed' => 0,
        ]];

        if ($channel) {
            $convIds = Conversation::where('workspace_id', $wsId)->where('channel', 'viber')
                ->where('raw_jid', 'like', 'viber:'.$channel->id.':%')->pluck('id');

            if ($convIds->isNotEmpty()) {
                $since = now()->subDays(6)->startOfDay();

                // Daily in/out volume.
                $daily = InboxMessage::whereIn('conversation_id', $convIds)->where('provider', 'viber')
                    ->where('created_at', '>=', $since)
                    ->selectRaw('DATE(created_at) d, direction, COUNT(*) c')
                    ->groupBy('d', 'direction')->get();
                $byDate = [];
                foreach ($daily as $r) {
                    $byDate[(string) $r->d][$r->direction === 'in' ? 'in' : 'out'] = (int) $r->c;
                }
                for ($i = 6; $i >= 0; $i--) {
                    $d = now()->subDays($i)->format('Y-m-d');
                    $data['rows'][] = ['date' => $d, 'in' => $byDate[$d]['in'] ?? 0, 'out' => $byDate[$d]['out'] ?? 0];
                    $data['totals']['in']  += $byDate[$d]['in'] ?? 0;
                    $data['totals']['out'] += $byDate[$d]['out'] ?? 0;
                }

                // Active users (distinct threads with an inbound in the window).
                $data['totals']['users'] = (int) Conversation::whereIn('id', $convIds)
                    ->where('last_inbound_at', '>=', $since)->count();

                // Read-receipt rates on outbound.
                $rec = InboxMessage::whereIn('conversation_id', $convIds)->where('provider', 'viber')->where('direction', 'out')
                    ->selectRaw('SUM(delivered_at IS NOT NULL) delivered, SUM(read_at IS NOT NULL) seen, SUM(status = "failed") failed')
                    ->first();
                $data['totals']['delivered'] = (int) ($rec->delivered ?? 0);
                $data['totals']['seen']      = (int) ($rec->seen ?? 0);
                $data['totals']['failed']    = (int) ($rec->failed ?? 0);
            }
        }

        return view('user.viber.insights', $data);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
