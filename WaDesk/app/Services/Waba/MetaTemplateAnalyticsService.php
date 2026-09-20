<?php

namespace App\Services\Waba;

use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Models\WpCampaign;
use Illuminate\Support\Facades\Log;

/**
 * Pull WhatsApp template BUTTON CLICK counts from Meta's own
 * `template_analytics` API and write them onto the campaign.
 *
 * WHY: Meta sends no click webhook, so campaign "Clicked" was permanently 0 for
 * WABA template sends. template_analytics is the only official source of clicks.
 * It is aggregate (per template, per day) — not per recipient — so we scope the
 * query to the campaign's own send window and attribute the template's clicks in
 * that window to the campaign. Good enough for the common case (one campaign per
 * template per window); over-counts only if two campaigns share a template on
 * the same days, which we note rather than hide.
 *
 * The number lands in `wpcampaigns.meta_clicked_count` (kept apart from the
 * redirect-based `clicked_count`); the UI shows the greater of the two.
 */
class MetaTemplateAnalyticsService
{
    /**
     * Sync one campaign's Meta click count. Returns the click total written, or
     * null if the campaign isn't a WABA template campaign / has no Meta template.
     */
    public function syncCampaign(WpCampaign $campaign): ?int
    {
        // Only WABA campaigns have Meta templates. Baileys/Twilio don't apply.
        if ((string) $campaign->provider !== 'waba') {
            return null;
        }

        // Collect every template this campaign sent (A/B included).
        $templateIds = array_values(array_filter([
            $campaign->template_id,
            $campaign->template_id_a,
            $campaign->template_id_b,
        ]));
        if (empty($templateIds)) return null;

        $templates = WaTemplate::query()
            ->whereIn('id', $templateIds)
            ->whereNotNull('meta_template_id')
            ->whereNotNull('provider_config_id')
            ->get();
        if ($templates->isEmpty()) return null;

        // Window: from the campaign's send day to now, day-aligned (Meta requires
        // day boundaries). Cap the lookback at 90 days (Meta's retention).
        $start = ($campaign->created_at ?? now())->copy()->startOfDay();
        $floor = now()->copy()->subDays(89)->startOfDay();
        if ($start->lt($floor)) $start = $floor;
        $end = now()->copy()->addDay()->startOfDay();   // exclusive upper bound

        $total = 0;

        // Group templates by the WABA they live on — one client + one call per WABA.
        foreach ($templates->groupBy('provider_config_id') as $cfgId => $group) {
            $cfg = WaProviderConfig::find($cfgId);
            if (!$cfg) continue;

            $metaIds = $group->pluck('meta_template_id')->map('strval')->filter()->unique()->values()->all();
            if (empty($metaIds)) continue;

            try {
                $client = new TemplateClient($cfg);
                $body   = $client->templateAnalytics($metaIds, $start->timestamp, $end->timestamp);
                $total += $this->sumClicks($body, $metaIds);
            } catch (\Throwable $e) {
                // Insights not enabled yet → turn it on once, then retry.
                if ($this->looksLikeInsightsDisabled($e)) {
                    try {
                        $client = new TemplateClient($cfg);
                        if ($client->enableInsights()) {
                            $body   = (new TemplateClient($cfg))->templateAnalytics($metaIds, $start->timestamp, $end->timestamp);
                            $total += $this->sumClicks($body, $metaIds);
                            continue;
                        }
                    } catch (\Throwable $e2) {
                        Log::warning('[WABA-TEMPLATE-CLICKS] enable+retry failed', ['campaign' => $campaign->id, 'error' => $e2->getMessage()]);
                    }
                }
                Log::warning('[WABA-TEMPLATE-CLICKS] analytics failed', [
                    'campaign' => $campaign->id, 'config' => $cfgId, 'error' => $e->getMessage(),
                ]);
            }
        }

        $campaign->forceFill([
            'meta_clicked_count'       => $total,
            'meta_analytics_synced_at' => now(),
        ])->save();

        Log::info('[WABA-TEMPLATE-CLICKS] synced', ['campaign' => $campaign->id, 'clicks' => $total]);
        return $total;
    }

    /**
     * Sum CLICKED counts out of a template_analytics response for the given
     * template ids. The clicked metric is an array of per-button objects, each
     * with a `count`; we sum across all buttons and all day buckets.
     */
    private function sumClicks(array $body, array $metaIds): int
    {
        $wanted = array_flip(array_map('strval', $metaIds));
        $sum = 0;

        foreach ((array) ($body['data'] ?? []) as $series) {
            foreach ((array) ($series['data_points'] ?? []) as $point) {
                $tid = (string) ($point['template_id'] ?? '');
                if ($tid !== '' && !isset($wanted[$tid])) continue;

                $clicked = $point['clicked'] ?? null;
                if (is_array($clicked)) {
                    foreach ($clicked as $btn) {
                        // Each button: {type, button_content, count}
                        $sum += (int) ($btn['count'] ?? 0);
                    }
                } elseif (is_numeric($clicked)) {
                    $sum += (int) $clicked;
                }
            }
        }
        return $sum;
    }

    private function looksLikeInsightsDisabled(\Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        return str_contains($m, 'insight') || str_contains($m, 'not enabled') || str_contains($m, 'analytics');
    }
}
