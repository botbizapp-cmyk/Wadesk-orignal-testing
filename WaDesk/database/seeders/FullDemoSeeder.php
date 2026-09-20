<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Workspace;

/**
 * ONE command to build the whole demo for the DEMO_SEED_EMAIL user
 * (default user@mediacity.co.in) across ALL of that user's workspaces.
 *
 * For each workspace it: grants every feature (plan_overrides), then runs the
 * base DemoContentSeeder + the channel showcase seeders + DemoGapSeeder — so
 * every user-side page opens with data and every channel shows connected.
 *
 * NON-DESTRUCTIVE — it only ADDS/refreshes the demo user's own demo rows (each
 * inner seeder is idempotent and wipes only its own workspace-scoped rows). It
 * does NOT delete other users/workspaces; the DB wipe is a separate step.
 *
 * Run:  php artisan db:seed --class="Database\\Seeders\\FullDemoSeeder"
 * Target one workspace only:  DEMO_SEED_WS=1 php artisan db:seed --class=...
 */
class FullDemoSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('DEMO_SEED_EMAIL', 'user@mediacity.co.in');
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->command->error("FullDemoSeeder: no user {$email} — create the demo user first.");
            return;
        }

        // Which workspaces to seed: an explicit DEMO_SEED_WS, else every live
        // workspace this user owns (Media City + NextLine).
        $wsIds = env('DEMO_SEED_WS')
            ? [(int) env('DEMO_SEED_WS')]
            : Workspace::where('owner_user_id', $user->id)->pluck('id')->map(fn ($x) => (int) $x)->all();

        if (empty($wsIds)) {
            $this->command->error("FullDemoSeeder: {$email} owns no workspaces to seed.");
            return;
        }

        $origWs = $user->current_workspace_id;

        foreach ($wsIds as $ws) {
            $this->command->info("── Seeding workspace {$ws} for {$email} ──");

            // Point every inner seeder at THIS workspace. DemoContentSeeder keys
            // off the user's current_workspace_id; the showcase seeders + the gap
            // seeder key off their *_SEED_WS env. Set both.
            $user->forceFill(['current_workspace_id' => $ws])->saveQuietly();
            foreach (['DEMO_SEED_WS', 'FLOW_SEED_WS_ID', 'FB_SEED_WS', 'TT_SEED_WS', 'TG_SEED_WS', 'SMS_SEED_WS'] as $k) {
                putenv("{$k}={$ws}"); $_ENV[$k] = (string) $ws; $_SERVER[$k] = (string) $ws;
            }
            putenv("DEMO_SEED_EMAIL={$email}"); $_ENV['DEMO_SEED_EMAIL'] = $email;

            $this->grantAllFeatures($ws);

            // Base ~20 tables (contacts, inbox, campaigns, templates, flows, …).
            $this->safeCall(DemoContentSeeder::class);

            // Channel showcase demos (each retargeted by its *_SEED_WS env).
            foreach ([
                FlowShowcaseSeeder::class,
                FacebookShowcaseSeeder::class,
                TiktokShowcaseSeeder::class,
                TelegramShowcaseSeeder::class,
                SmsShowcaseSeeder::class,
                InstagramTemplateShowcaseSeeder::class,
            ] as $seeder) {
                $this->safeCall($seeder);
            }

            // Everything the base seeder misses: channels, CRM, commerce, AI, misc.
            $this->safeCall(DemoGapSeeder::class);
        }

        // Restore the user's original current workspace.
        $user->forceFill(['current_workspace_id' => $origWs ?: $wsIds[0]])->saveQuietly();
        $this->command->info('FullDemoSeeder complete.');
    }

    /** Call a seeder but never let one missing/failing seeder abort the rest. */
    private function safeCall(string $class): void
    {
        if (!class_exists($class)) {
            $this->command->warn("  skip {$class} (not present)");
            return;
        }
        try {
            $this->call($class);
        } catch (\Throwable $e) {
            $this->command->warn("  {$class} failed: {$e->getMessage()}");
        }
    }

    /**
     * Grant EVERY feature to the workspace via plan_overrides (checked first in
     * Workspace::effectiveLimit): each access_/integration_/allow_ boolean → true,
     * each *_limit → -1 (unlimited). Derived from the live packages columns so it
     * stays in sync with whatever flags exist. Also clears the usage ledger.
     */
    private function grantAllFeatures(int $ws): void
    {
        try {
            $overrides = [];
            foreach (Schema::getColumnListing('packages') as $c) {
                if (str_ends_with($c, '_limit')) {
                    $overrides[$c] = -1;
                } elseif (str_starts_with($c, 'access_') || str_starts_with($c, 'integration_') || str_starts_with($c, 'allow_')) {
                    $overrides[$c] = true;
                }
            }
            // A few common non-prefixed booleans seen on packages.
            foreach (['remove_branding', 'white_label', 'chatgpt_suggestion', 'autoflow', 'template'] as $flag) {
                if (Schema::hasColumn('packages', $flag)) $overrides[$flag] = true;
            }

            $wsModel = Workspace::find($ws);
            if ($wsModel) {
                $wsModel->plan_overrides = $overrides;   // array cast → JSON
                $wsModel->saveQuietly();
            }
            \Illuminate\Support\Facades\DB::table('plan_usage')->where('workspace_id', $ws)->delete();
            $this->command->info('  granted all features (plan_overrides) + reset quota');
        } catch (\Throwable $e) {
            $this->command->warn("  grantAllFeatures failed: {$e->getMessage()}");
        }
    }
}
