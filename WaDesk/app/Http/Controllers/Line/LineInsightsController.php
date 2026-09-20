<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\LineChannel;
use App\Services\Line\LineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * LINE insights — the OA's push-quota, follower counts, per-day delivery and
 * friend demographics, read live from the Messaging API insight + quota
 * endpoints. Read-only dashboard; no state stored. Quota also doubles as the
 * billing guardrail (how many pushes remain this period).
 */
class LineInsightsController extends Controller
{
    public function index(Request $request): View
    {
        $wsId     = $this->workspaceId();
        $channels = LineChannel::allForWorkspace($wsId);

        $channelId = (int) $request->integer('channel');
        $channel   = $channelId > 0 ? $channels->firstWhere('id', $channelId) : $channels->first();

        $data = [
            'channels' => $channels,
            'channel'  => $channel,
            'quota'    => null,
            'used'     => null,
            'remaining'=> null,
            'followers'=> null,
            'delivery' => null,
            'demographic' => null,
            'date'     => null,
            'error'    => null,
        ];

        if ($channel) {
            // LINE insight data lands for the PREVIOUS UTC day; today returns empty.
            $date = now('UTC')->subDay()->format('Ymd');
            $data['date'] = $date;
            try {
                $client = new LineClient((string) $channel->activeAccessToken());

                $quota = $client->messageQuota();
                $cons  = $client->messageQuotaConsumption();
                if ($quota['ok'] ?? false) {
                    $type = (string) ($quota['data']['type'] ?? 'none');
                    $data['quota'] = $type === 'limited' ? (int) ($quota['data']['value'] ?? 0) : null; // null = unlimited
                }
                if ($cons['ok'] ?? false) {
                    $data['used'] = (int) ($cons['data']['totalUsage'] ?? 0);
                }
                if ($data['quota'] !== null && $data['used'] !== null) {
                    $data['remaining'] = max(0, $data['quota'] - $data['used']);
                }

                $followers = $client->insightFollowers($date);
                if (($followers['ok'] ?? false) && ($followers['data']['status'] ?? '') === 'ready') {
                    $data['followers'] = $followers['data'];
                }

                $delivery = $client->insightMessageDelivery($date);
                if (($delivery['ok'] ?? false) && ($delivery['data']['status'] ?? '') === 'ready') {
                    $data['delivery'] = $delivery['data'];
                }

                $demo = $client->insightDemographic();
                if (($demo['ok'] ?? false) && ($demo['data']['available'] ?? false)) {
                    $data['demographic'] = $demo['data'];
                }
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('user.line.insights', $data);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
