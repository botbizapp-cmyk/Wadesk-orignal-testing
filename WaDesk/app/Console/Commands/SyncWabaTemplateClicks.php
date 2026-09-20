<?php

namespace App\Console\Commands;

use App\Models\WpCampaign;
use App\Services\Waba\MetaTemplateAnalyticsService;
use Illuminate\Console\Command;

/**
 * Poll Meta's template_analytics for recent WABA campaigns and write their
 * button-click counts to meta_clicked_count. Clicks trickle in for days after a
 * send, so we re-sync any WABA campaign touched in the last N days.
 *
 *   php artisan waba:sync-template-clicks
 *   php artisan waba:sync-template-clicks --days=14 --campaign=42
 */
class SyncWabaTemplateClicks extends Command
{
    protected $signature = 'waba:sync-template-clicks {--days=14 : Re-sync WABA campaigns created within this many days} {--campaign= : Sync only this campaign id}';
    protected $description = 'Pull WhatsApp template button clicks from Meta template_analytics into campaigns';

    public function handle(MetaTemplateAnalyticsService $svc): int
    {
        $q = WpCampaign::query()->where('provider', 'waba');

        if ($id = $this->option('campaign')) {
            $q->where('id', (int) $id);
        } else {
            $days = max(1, (int) $this->option('days'));
            $q->where('created_at', '>=', now()->subDays($days));
        }

        $count = 0;
        $q->orderByDesc('id')->chunkById(100, function ($campaigns) use ($svc, &$count) {
            foreach ($campaigns as $c) {
                try {
                    $clicks = $svc->syncCampaign($c);
                    if ($clicks !== null) {
                        $count++;
                        $this->line("  #{$c->id} → {$clicks} clicks");
                    }
                } catch (\Throwable $e) {
                    $this->warn("  #{$c->id} failed: " . $e->getMessage());
                }
            }
        });

        $this->info("Synced {$count} WABA campaign(s).");
        return self::SUCCESS;
    }
}
