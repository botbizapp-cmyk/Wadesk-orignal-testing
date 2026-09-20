<?php

namespace App\Http\Controllers\WeChat;

use App\Http\Controllers\Controller;
use App\Models\WeChatChannel;
use App\Services\WeChat\WeChatClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * WeChat insights — follower analytics from the datacube API (new/cancel per day
 * + cumulative total). Read-only. WeChat caps a query at a 7-day range and data
 * lags ~1 day, so we pull the last 7 days ending yesterday. Mirrors
 * LineInsightsController.
 */
class WeChatInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $wsId     = $this->workspaceId();
        $channels = WeChatChannel::allForWorkspace($wsId);
        $channel  = $request->integer('channel') > 0
            ? $channels->firstWhere('id', $request->integer('channel'))
            : $channels->first();

        $data = ['channels' => $channels, 'channel' => $channel, 'rows' => [], 'total' => null, 'newSum' => 0, 'cancelSum' => 0, 'error' => null];

        if ($channel) {
            // Datacube data lags ~1 day; pull the 7 days ending yesterday.
            $end   = now('UTC')->subDay();
            $begin = (clone $end)->subDays(6);
            try {
                $client = new WeChatClient($channel);
                $sum    = $client->getUserSummary($begin->format('Y-m-d'), $end->format('Y-m-d'));
                $cum    = $client->getUserCumulate($begin->format('Y-m-d'), $end->format('Y-m-d'));

                $byDate = [];
                if ($sum['ok'] ?? false) {
                    foreach ((array) data_get($sum, 'data.list', []) as $r) {
                        $d = (string) ($r['ref_date'] ?? '');
                        $byDate[$d]['new']    = ($byDate[$d]['new'] ?? 0) + (int) ($r['new_user'] ?? 0);
                        $byDate[$d]['cancel'] = ($byDate[$d]['cancel'] ?? 0) + (int) ($r['cancel_user'] ?? 0);
                    }
                }
                if ($cum['ok'] ?? false) {
                    foreach ((array) data_get($cum, 'data.list', []) as $r) {
                        $d = (string) ($r['ref_date'] ?? '');
                        $byDate[$d]['total'] = (int) ($r['cumulate_user'] ?? 0);
                    }
                }
                ksort($byDate);
                foreach ($byDate as $d => $v) {
                    $data['rows'][] = ['date' => $d, 'new' => $v['new'] ?? 0, 'cancel' => $v['cancel'] ?? 0, 'total' => $v['total'] ?? null];
                    $data['newSum']    += $v['new'] ?? 0;
                    $data['cancelSum'] += $v['cancel'] ?? 0;
                }
                $last = end($data['rows']);
                $data['total'] = $last ? $last['total'] : null;

                if (! ($sum['ok'] ?? false) && ! ($cum['ok'] ?? false)) {
                    $data['error'] = (string) ($sum['error'] ?? $cum['error'] ?? 'no data');
                }
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('user.wechat.insights', $data);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
