<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Fills every feature area the base DemoContentSeeder does NOT cover, so no
 * user-side page opens empty: connected channel accounts (all engines), CRM
 * (pipeline/deals/companies/projects/tasks/proposals/estimates/invoices/
 * payments/appointments), commerce (storefront/products/catalog), AI
 * (usage/assistants/widget/copilot/BYOK) and misc (leads/saved-replies/
 * routing/SLA/warmer).
 *
 * Target workspace = env('DEMO_SEED_WS') if set, else the DEMO_SEED_EMAIL user's
 * current_workspace_id (same resolution as DemoContentSeeder). Idempotent: it
 * wipes its own rows for the workspace first. Everything runs inside
 * Model::withoutEvents so no observer can fire a real WhatsApp/Meta call, and
 * encrypted casts still encrypt the fake tokens under this install's APP_KEY.
 */
class DemoGapSeeder extends Seeder
{
    private int $wsId = 0;
    private int $ownerId = 0;
    private array $report = [];

    public function run(): void
    {
        [$this->wsId, $this->ownerId] = $this->resolveTarget();
        if ($this->wsId <= 0) {
            $this->log('ABORT: could not resolve a target workspace (set DEMO_SEED_WS or DEMO_SEED_EMAIL).');
            return;
        }
        $this->log("DemoGapSeeder → workspace {$this->wsId}, owner user {$this->ownerId}");

        Model::withoutEvents(function () {
            $this->cleanup();
            $this->section('channels', fn () => $this->seedChannels());
            $this->section('crm',      fn () => $this->seedCrm());
            $this->section('commerce', fn () => $this->seedCommerce());
            $this->section('ai',       fn () => $this->seedAi());
            $this->section('misc',     fn () => $this->seedMisc());
        });

        $this->log('DemoGapSeeder done: ' . implode(' | ', $this->report));
    }

    // ── target + helpers ────────────────────────────────────────────────────
    private function resolveTarget(): array
    {
        $wsId = (int) env('DEMO_SEED_WS', 0);
        $email = env('DEMO_SEED_EMAIL', 'user@mediacity.co.in');
        $user = \App\Models\User::where('email', $email)->first();
        if (!$wsId && $user) {
            $wsId = (int) ($user->current_workspace_id ?? 0);
        }
        // Owner = the workspace's owner_user_id if present, else the resolved user.
        $ownerId = (int) (optional(\App\Models\Workspace::find($wsId))->owner_user_id
            ?: ($user->id ?? 0));
        return [$wsId, $ownerId];
    }

    private function section(string $name, \Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->report[] = "{$name}=FAILED({$e->getMessage()})";
            $this->log("[{$name}] FAILED: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
        }
    }

    private function log(string $m): void
    {
        if ($this->command) $this->command->getOutput()->writeln($m);
        else echo $m . "\n";
    }

    private function contactIds(int $limit = 12): array
    {
        return \App\Models\Contact::where('workspace_id', $this->wsId)->limit($limit)->pluck('id')->all();
    }

    // ── cleanup (idempotent re-run) ─────────────────────────────────────────
    private function cleanup(): void
    {
        $ws = $this->wsId;
        // Child-by-parent first.
        $invIds = DB::table('invoices')->where('workspace_id', $ws)->pluck('id');
        if ($invIds->count()) DB::table('invoice_items')->whereIn('invoice_id', $invIds)->delete();

        foreach ([
            'deal_activities', 'deals', 'pipeline_stages', 'pipelines', 'companies', 'projects', 'tasks',
            'sales_docs', 'payments', 'invoices', 'appointments', 'booking_types',
            'wa_products', 'wa_storefronts', 'wa_catalogs',
            'ai_token_usage', 'ai_training_sources', 'ai_chat_assistants', 'chatbot_widgets',
            'ai_crm_actions', 'crm_briefs', 'ai_provider_keys',
            'leads', 'saved_replies', 'routing_rules', 'sla_policies',
            'facebook_posts', 'tiktok_posts',
            'facebook_pages', 'tiktok_accounts', 'telegram_bots', 'workspace_ig_accounts',
            'wa_provider_configs', 'devices',
        ] as $t) {
            try { DB::table($t)->where('workspace_id', $ws)->delete(); } catch (\Throwable $e) { /* table may not exist */ }
        }
    }

    // ── Phase 4: connected channel accounts (all engines) ───────────────────
    private function seedChannels(): void
    {
        $ws = $this->wsId; $u = $this->ownerId;

        $device = \App\Models\Device::create([
            'workspace_id' => $ws, 'user_id' => $u, 'assigned_user_id' => $u,
            'device_name' => 'Demo Sales Line', 'country_code' => '1', 'phone_number' => '5550100100',
            'region' => 'US', 'active' => true, 'status' => 'connected',
            'sent_24h' => 128, 'failed_24h' => 2, 'last_seen_at' => now(),
            'warmer_config' => ['enabled' => true, 'daily_base' => 40, 'step_pct' => 20, 'max_daily' => 400, 'gap_min' => 25, 'gap_max' => 90, 'active_start' => '09:00', 'active_end' => '20:00', 'started_at' => now()->subDays(6)->toDateTimeString()],
            'warm_day' => 6, 'warm_day_count' => 96,
        ]);

        // WABA (primary) + Twilio + SMS via wa_provider_configs.
        $waba = new \App\Models\WaProviderConfig([
            'workspace_id' => $ws, 'provider' => 'waba', 'status' => 'connected', 'is_primary' => true,
            'phone_number' => '+15550100200', 'display_label' => 'Demo WABA', 'connected_at' => now(),
            'meta_json' => ['verified_name' => 'Demo Co', 'quality' => 'GREEN'],
        ]);
        $creds = ['app_id' => '000000000000000', 'app_secret' => 'demo_secret', 'waba_id' => '111111111111111', 'business_id' => '222222222222222', 'phone_number_id' => '333333333333333', 'access_token' => 'EAAG-demo-token', 'webhook_verify_token' => 'demo_verify', 'register_pin' => '000000'];
        if (method_exists($waba, 'setCreds')) $waba->setCreds($creds); else $waba->credentials_json = $creds;
        $waba->save();

        $tw = new \App\Models\WaProviderConfig(['workspace_id' => $ws, 'provider' => 'twilio', 'status' => 'connected', 'phone_number' => '+15550100300', 'display_label' => 'Demo Twilio', 'connected_at' => now()]);
        $twc = ['account_sid' => 'ACdemo', 'auth_token' => 'demo', 'from_number' => '+15550100300', 'sandbox' => false];
        if (method_exists($tw, 'setCreds')) $tw->setCreds($twc); else $tw->credentials_json = $twc;
        $tw->save();

        $sms = new \App\Models\WaProviderConfig(['workspace_id' => $ws, 'provider' => 'sms', 'status' => 'connected', 'phone_number' => '+15550100400', 'display_label' => 'Demo SMS', 'connected_at' => now()]);
        $smsc = ['provider' => 'twilio', 'account_sid' => 'ACdemo', 'auth_token' => 'demo', 'from_number' => '+15550100400'];
        if (method_exists($sms, 'setCreds')) $sms->setCreds($smsc); else $sms->credentials_json = $smsc;
        $sms->save();

        $fb = \App\Models\FacebookPage::create([
            'workspace_id' => $ws, 'user_id' => $u, 'page_id' => '100000000000001', 'name' => 'Demo Brand Page',
            'category' => 'Software', 'username' => 'demobrand', 'access_token' => 'EAAG-demo-page-token',
            'token_expires_at' => null, 'scopes' => ['pages_messaging', 'pages_manage_posts'],
            'tasks' => ['MANAGE', 'CREATE_CONTENT', 'MODERATE', 'MESSAGING', 'ANALYZE'],
            'status' => 'connected', 'connect_method' => 'manual', 'fan_count' => 3400, 'meta_json' => [],
        ]);
        foreach ([['Big summer sale is live!', 'published'], ['New arrivals dropping Friday', 'scheduled']] as $i => [$msg, $st]) {
            DB::table('facebook_posts')->insert([
                'workspace_id' => $ws, 'facebook_page_id' => $fb->id, 'user_id' => $u,
                'fb_post_id' => $st === 'published' ? '100000000000001_' . (900 + $i) : null,
                'type' => 'status', 'status' => $st, 'message' => $msg, 'link' => null, 'media_json' => null,
                'scheduled_publish_time' => $st === 'scheduled' ? now()->addDays(2) : null,
                'published_at' => $st === 'published' ? now()->subDays($i + 1) : null,
                'error' => null, 'meta_json' => null, 'created_at' => now()->subDays($i + 1), 'updated_at' => now(),
            ]);
        }

        $tt = \App\Models\TiktokAccount::create([
            'workspace_id' => $ws, 'user_id' => $u, 'open_id' => 'demo-open-id', 'display_name' => 'Demo Creator',
            'username' => 'democreator', 'is_verified' => true, 'follower_count' => 50200, 'following_count' => 180,
            'likes_count' => 210400, 'video_count' => 42, 'access_token' => 'demo-access', 'refresh_token' => 'demo-refresh',
            'token_expires_at' => now()->addHours(20), 'refresh_expires_at' => now()->addDays(300),
            'scopes' => ['user.info.basic', 'video.list', 'video.publish'], 'status' => 'connected', 'connect_method' => 'oauth', 'meta_json' => [],
        ]);
        DB::table('tiktok_posts')->insert([
            'workspace_id' => $ws, 'tiktok_account_id' => $tt->id, 'user_id' => $u, 'type' => 'video', 'status' => 'published',
            'caption' => 'Behind the scenes at Demo Co', 'media_json' => json_encode(['video_url' => 'https://example.com/v.mp4', 'privacy' => 'PUBLIC_TO_EVERYONE']),
            'publish_id' => 'pub_demo_1', 'tiktok_post_id' => 'ttp_demo_1', 'scheduled_at' => null, 'published_at' => now()->subDays(3),
            'error' => null, 'meta_json' => null, 'created_at' => now()->subDays(3), 'updated_at' => now(),
        ]);

        \App\Models\TelegramBot::create([
            'workspace_id' => $ws, 'connected_by' => $u, 'bot_token' => '123456:demo-bot-token',
            'bot_username' => 'demo_wadesk_bot', 'bot_name' => 'Demo Bot', 'bot_id' => '123456',
            'webhook_token' => Str::random(48), 'secret_token' => Str::random(48), 'active' => true, 'connected_at' => now(),
        ]);

        \App\Models\WorkspaceIgAccount::create([
            'workspace_id' => $ws, 'instaflow_account_id' => '900001', 'username' => 'demo.brand',
            'name' => 'Demo Brand', 'avatar' => null, 'status' => 'connected', 'followers' => 12500, 'synced_at' => now(),
        ]);

        // Global platform toggles so the non-WhatsApp channels surface.
        $this->setSetting('allowed_send_methods', ['baileys', 'waba', 'twilio']);
        $this->setSetting('default_send_method', 'baileys');
        foreach (['facebook_enabled', 'telegram_enabled', 'sms_enabled', 'tiktok_inbox_enabled', 'instaflow_connected'] as $k) $this->setSetting($k, true);
        $this->setSetting('instaflow_url', 'https://demo.instaflow.local');
        $this->setSetting('instaflow_secret', 'demo-instaflow-secret');

        $this->report[] = 'channels=ok';
    }

    private function setSetting(string $key, $val): void
    {
        try { \App\Models\SystemSetting::set($key, $val); } catch (\Throwable $e) { /* setting model optional */ }
    }

    // ── CRM ─────────────────────────────────────────────────────────────────
    private function seedCrm(): void
    {
        $ws = $this->wsId; $u = $this->ownerId; $contacts = $this->contactIds();

        // Pipeline + 6 default stages via the model helper.
        $pipeline = \App\Models\Pipeline::ensureDefaultForWorkspace($ws);
        $stages = \App\Models\PipelineStage::where('pipeline_id', $pipeline->id)->orderBy('sort_order')->get();

        // Companies.
        $companies = [];
        foreach ([['Acme Retail', 'Retail'], ['Globex Media', 'Marketing'], ['Initech Software', 'Software']] as [$name, $ind]) {
            $companies[] = \App\Models\Company::create([
                'workspace_id' => $ws, 'user_id' => $u, 'owner_user_id' => $u, 'name' => $name,
                'email' => 'hello@' . Str::slug($name) . '.com', 'phone' => '+1555' . random_int(1000000, 9999999),
                'website' => 'https://' . Str::slug($name) . '.com', 'industry' => $ind, 'size_range' => '11-50',
                'address' => '100 Market St', 'notes' => 'Demo company', 'custom_attributes' => [],
            ])->id;
        }

        // Deals spread across stages (most open, one won, one lost).
        $won = $stages->firstWhere('is_won', true); $lost = $stages->firstWhere('is_lost', true);
        $open = $stages->filter(fn ($s) => !$s->is_won && !$s->is_lost)->values();
        $titles = ['Website revamp', 'Q3 WhatsApp campaign', 'Annual support renewal', 'Onboarding package', 'Catalog integration', 'Enterprise rollout'];
        foreach ($titles as $i => $t) {
            $stage = $open->get($i % max(1, $open->count())) ?: $stages->first();
            $deal = \App\Models\Deal::create([
                'workspace_id' => $ws, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
                'contact_id' => $contacts[$i % max(1, count($contacts))] ?? null, 'company_id' => $companies[$i % count($companies)],
                'title' => $t, 'value_minor' => random_int(50, 900) * 1000, 'currency' => 'USD', 'owner_user_id' => $u,
                'expected_close_date' => now()->addDays(($i + 1) * 5)->toDateString(), 'status' => 'open', 'source' => 'inbox',
                'sort_order' => $i, 'notes' => 'Demo deal',
            ]);
            \App\Models\DealActivity::create(['deal_id' => $deal->id, 'workspace_id' => $ws, 'user_id' => $u, 'type' => 'note', 'body' => 'Created from demo seed.']);
        }
        if ($won) {
            \App\Models\Deal::create(['workspace_id' => $ws, 'pipeline_id' => $pipeline->id, 'stage_id' => $won->id, 'company_id' => $companies[0], 'title' => 'Closed: Launch retainer', 'value_minor' => 1200000, 'currency' => 'USD', 'owner_user_id' => $u, 'status' => 'won', 'won_at' => now()->subDays(4), 'source' => 'manual', 'sort_order' => 0]);
        }
        if ($lost) {
            \App\Models\Deal::create(['workspace_id' => $ws, 'pipeline_id' => $pipeline->id, 'stage_id' => $lost->id, 'company_id' => $companies[1], 'title' => 'Lost: Trial not converted', 'value_minor' => 300000, 'currency' => 'USD', 'owner_user_id' => $u, 'status' => 'lost', 'lost_reason' => 'Budget', 'lost_at' => now()->subDays(2), 'source' => 'manual', 'sort_order' => 0]);
        }

        // Projects + tasks.
        foreach ([['Website revamp', 'in_progress', 45], ['CRM migration', 'in_progress', 70], ['Holiday campaign', 'completed', 100]] as [$n, $st, $pr]) {
            \App\Models\Project::create(['workspace_id' => $ws, 'name' => $n, 'description' => 'Demo project', 'status' => $st, 'progress' => $pr, 'company_id' => $companies[0], 'owner_id' => $u, 'created_by' => $u, 'start_date' => now()->subDays(20)->toDateString(), 'due_date' => now()->addDays(20)->toDateString(), 'completed_at' => $st === 'completed' ? now()->subDays(1) : null]);
        }
        foreach (['Call Acme about renewal', 'Send proposal to Globex', 'Prepare Q3 report', 'Follow up on invoice', 'Schedule onboarding'] as $i => $t) {
            \App\Models\Task::create(['workspace_id' => $ws, 'created_by' => $u, 'assignee_id' => $u, 'title' => $t, 'notes' => 'Demo task', 'priority' => ['low', 'medium', 'high'][$i % 3], 'status' => $i < 3 ? 'open' : 'done', 'due_at' => now()->addDays($i + 1), 'done_at' => $i < 3 ? null : now()->subDay()]);
        }

        // Proposals + estimates.
        foreach ([['proposal', 'PRO-1001', 'accepted'], ['proposal', 'PRO-1002', 'sent'], ['estimate', 'EST-2001', 'sent'], ['estimate', 'EST-2002', 'draft']] as $i => [$type, $num, $st]) {
            $items = [['name' => 'Setup & onboarding', 'qty' => 1, 'price_minor' => 250000], ['name' => 'Monthly retainer', 'qty' => 3, 'price_minor' => 90000]];
            $sub = 250000 + 3 * 90000;
            \App\Models\SalesDoc::create(['workspace_id' => $ws, 'doc_type' => $type, 'number' => $num, 'seq' => 1000 + $i, 'status' => $st, 'title' => ucfirst($type) . ' for Acme', 'company_id' => $companies[0], 'buyer_name' => 'Acme Retail', 'buyer_email' => 'ap@acme.com', 'currency' => 'USD', 'currency_exponent' => 2, 'subtotal_minor' => $sub, 'discount_minor' => 0, 'tax_minor' => (int) round($sub * 0.1), 'total_minor' => (int) round($sub * 1.1), 'items_json' => $items, 'notes' => 'Demo doc', 'valid_until' => now()->addDays(14)->toDateString(), 'public_token' => Str::random(32), 'owner_id' => $u, 'created_by' => $u]);
        }

        // Invoices + items + payments.
        foreach ([['INV-3001', 'paid'], ['INV-3002', 'issued'], ['INV-3003', 'issued']] as $i => [$num, $st]) {
            $sub = 300000 + $i * 50000; $tax = (int) round($sub * 0.1); $total = $sub + $tax;
            $inv = \App\Models\Invoice::create(['workspace_id' => $ws, 'user_id' => $u, 'source' => 'own', 'doc_type' => 'invoice', 'series' => 'INV', 'invoice_number' => $num, 'seq' => 3000 + $i, 'status' => $st, 'send_status' => $st === 'paid' ? 'sent' : 'ready', 'issued_at' => now()->subDays(10 - $i), 'due_at' => now()->addDays(5), 'paid_at' => $st === 'paid' ? now()->subDays(3) : null, 'currency' => 'USD', 'currency_exponent' => 2, 'subtotal_minor' => $sub, 'tax_minor' => $tax, 'total_minor' => $total, 'buyer_name' => 'Acme Retail', 'buyer_email' => 'ap@acme.com', 'public_token' => Str::random(32)]);
            \App\Models\InvoiceItem::create(['invoice_id' => $inv->id, 'sort' => 0, 'description' => 'Professional services', 'qty' => 1, 'unit_price_minor' => $sub, 'line_subtotal_minor' => $sub, 'tax_rate' => 10, 'tax_amount_minor' => $tax, 'currency' => 'USD']);
            if ($st === 'paid') {
                \App\Models\Payment::create(['workspace_id' => $ws, 'invoice_id' => $inv->id, 'company_id' => $companies[0], 'amount_minor' => $total, 'currency' => 'USD', 'method' => 'bank', 'source' => 'manual', 'paid_at' => now()->subDays(3), 'reference' => 'TRX-' . random_int(10000, 99999), 'recorded_by' => $u]);
            }
        }

        // Appointments: booking types + booked appointments.
        $bt = [];
        foreach ([['Intro Call', 30], ['Strategy Session', 60]] as [$n, $dur]) {
            $bt[] = \App\Models\BookingType::create(['workspace_id' => $ws, 'user_id' => $u, 'name' => $n, 'slug' => Str::slug($n), 'description' => 'Demo service', 'location_type' => 'virtual', 'color' => '#4f46e5', 'duration_minutes' => $dur, 'increment_minutes' => 15, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 5, 'min_notice_minutes' => 60, 'max_advance_days' => 30, 'timezone' => 'UTC', 'is_active' => true, 'sort_order' => 0])->id;
        }
        foreach (['confirmed', 'confirmed', 'completed', 'pending'] as $i => $st) {
            \App\Models\Appointment::create(['workspace_id' => $ws, 'provider' => 'baileys', 'user_id' => $u, 'contact_id' => $contacts[$i % max(1, count($contacts))] ?? null, 'booking_type_id' => $bt[$i % count($bt)], 'title' => 'Demo booking', 'starts_at' => now()->addDays($i + 1)->setTime(10, 0), 'ends_at' => now()->addDays($i + 1)->setTime(10, 30), 'timezone' => 'UTC', 'status' => $st, 'source' => 'chat', 'manage_token' => Str::random(24)]);
        }

        $this->report[] = 'crm=ok';
    }

    // ── Commerce ────────────────────────────────────────────────────────────
    private function seedCommerce(): void
    {
        $ws = $this->wsId; $u = $this->ownerId;
        $device = \App\Models\Device::where('workspace_id', $ws)->first();

        $store = \App\Models\WaStorefront::create(['workspace_id' => $ws, 'device_id' => $device?->id, 'shop_name' => 'Demo Shop', 'slug' => 'demo-shop-' . $ws, 'theme_key' => 'aurora', 'enabled' => true, 'settings_json' => [], 'shipping_json' => [], 'payment_provider' => 'manual', 'currency_code' => 'USD']);

        $names = [['Wireless Earbuds', 4999], ['Smart Watch', 12999], ['Phone Case', 1499], ['USB-C Cable', 899], ['Power Bank', 3499], ['Bluetooth Speaker', 5999]];
        foreach ($names as $i => [$n, $price]) {
            \App\Models\WaProduct::create(['workspace_id' => $ws, 'user_id' => $u, 'storefront_id' => $store->id, 'sku' => 'SKU-' . (1000 + $i), 'name' => $n, 'slug' => Str::slug($n) . '-' . $ws, 'description' => 'Demo product ' . $n, 'price_minor' => $price, 'compare_price_minor' => $price + 1000, 'currency_code' => 'USD', 'image_url' => 'https://placehold.co/600x600?text=' . urlencode($n), 'in_stock' => true, 'stock_qty' => random_int(5, 80), 'sort_order' => $i, 'status' => 'active', 'availability' => 'in stock', 'category' => 'Electronics']);
        }

        \App\Models\WaCatalog::create(['workspace_id' => $ws, 'provider' => 'meta_cloud', 'catalog_id' => 'cat_demo_1', 'catalog_name' => 'Demo Catalog', 'waba_id' => '111111111111111', 'phone_number_id' => '333333333333333', 'is_cart_enabled' => true, 'is_catalog_visible' => true, 'meta_json' => []]);

        $this->report[] = 'commerce=ok';
    }

    // ── AI ──────────────────────────────────────────────────────────────────
    private function seedAi(): void
    {
        $ws = $this->wsId; $u = $this->ownerId;

        // ai_token_usage — spread over 60 days, mixed providers/models + billed split, for full charts.
        $providers = [['openai', 'gpt-4o-mini'], ['anthropic', 'claude-haiku-4-5'], ['gemini', 'gemini-1.5-flash']];
        $rows = [];
        for ($d = 0; $d < 60; $d++) {
            foreach ($providers as $pi => [$prov, $model]) {
                if (($d + $pi) % 2 !== 0) continue; // thin it out a little
                $pt = random_int(200, 1500); $ct = random_int(100, 900);
                $rows[] = ['workspace_id' => $ws, 'provider' => $prov, 'model' => $model, 'prompt_tokens' => $pt, 'completion_tokens' => $ct, 'total_tokens' => $pt + $ct, 'billed_against' => ($d % 3 === 0) ? 'admin' : 'workspace', 'created_at' => now()->subDays($d)->subHours($pi * 2)];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) DB::table('ai_token_usage')->insert($chunk);

        $assistant = \App\Models\AiChatAssistant::create(['workspace_id' => $ws, 'user_id' => $u, 'name' => 'Demo Support Bot', 'slug' => 'demo-support-bot', 'greeting' => 'Hi! How can I help you today?', 'system_prompt' => 'You are a friendly support agent for Demo Co.', 'tone' => 'friendly', 'language' => 'en', 'ai_provider' => 'openai', 'ai_model' => 'gpt-4o-mini', 'reply_max_tokens' => 500, 'temperature' => 0.6, 'fallback_message' => 'Let me connect you to a human.', 'handoff_enabled' => true, 'handoff_keyword' => 'agent', 'status' => 'active']);
        foreach ([['faq', 'Shipping FAQ'], ['url', 'Website'], ['qa', 'Returns policy']] as [$kind, $label]) {
            \App\Models\AiTrainingSource::create(['workspace_id' => $ws, 'assistant_id' => $assistant->id, 'user_id' => $u, 'kind' => $kind, 'label' => $label, 'url' => $kind === 'url' ? 'https://demo.co' : null, 'content' => $kind === 'faq' ? 'We ship worldwide in 3-5 days.' : null, 'question' => $kind === 'qa' ? 'What is the return window?' : null, 'answer' => $kind === 'qa' ? '30 days.' : null, 'status' => 'ready', 'tokens_estimate' => 120]);
        }

        \App\Models\ChatbotWidget::create(['workspace_id' => $ws, 'user_id' => $u, 'assistant_id' => $assistant->id, 'name' => 'Website Widget', 'slug' => 'website-widget-' . $ws, 'embed_token' => Str::random(24), 'mode' => 'both', 'header_title' => 'Chat with us', 'welcome_message' => 'Hi there! 👋', 'position' => 'right', 'button_color' => '#25D366', 'status' => 'active', 'allowed_domains' => ['demo.co']]);

        foreach ([['summarize_thread', 'read'], ['draft_reply', 'read'], ['create_deal', 'write'], ['tag_contact', 'write'], ['extract_order', 'read']] as $i => [$tool, $kind]) {
            \App\Models\AiCrmAction::create(['workspace_id' => $ws, 'user_id' => $u, 'channel' => 'whatsapp', 'tool' => $tool, 'kind' => $kind, 'status' => 'ok', 'params' => ['demo' => true], 'result_summary' => 'Demo AI action ' . $tool, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'tokens' => random_int(200, 1200), 'subject_type' => 'Contact', 'subject_id' => null]);
        }
        $briefCompany = \App\Models\Company::where('workspace_id', $ws)->value('id');
        $briefContact = $this->contactIds(1)[0] ?? null;
        foreach ([['company', $briefCompany, 'Acme Retail — account brief'], ['contact', $briefContact, 'Top lead — conversation summary']] as [$stype, $sid, $t]) {
            if (!$sid) continue; // subject_id is NOT NULL
            \App\Models\CrmBrief::create(['workspace_id' => $ws, 'created_by' => $u, 'subject_type' => $stype, 'subject_id' => $sid, 'title' => $t, 'html' => '<h2>' . $t . '</h2><p>Demo AI-generated brief.</p>', 'summary' => 'Demo brief', 'public_token' => Str::random(24), 'meta_json' => []]);
        }

        foreach ([['openai', true], ['anthropic', false]] as [$prov, $active]) {
            \App\Models\AiProviderKey::create(['workspace_id' => $ws, 'provider' => $prov, 'api_key' => 'demo-' . $prov . '-key', 'is_active' => $active]);
        }

        $this->report[] = 'ai=ok';
    }

    // ── Misc ────────────────────────────────────────────────────────────────
    private function seedMisc(): void
    {
        $ws = $this->wsId; $u = $this->ownerId;

        foreach (['Riverside Cafe', 'Downtown Dental', 'Green Grocers', 'Peak Fitness', 'Sunset Salon', 'Metro Motors', 'Bright Bakery', 'Urban Threads'] as $i => $n) {
            \App\Models\Lead::create(['workspace_id' => $ws, 'user_id' => $u, 'source' => 'osm', 'external_id' => 'osm_' . (1000 + $i), 'name' => $n, 'category' => ['cafe', 'dentist', 'grocery', 'gym', 'salon', 'car_dealer', 'bakery', 'clothing'][$i], 'phone' => '+1555' . random_int(2000000, 2999999), 'phone_e164' => '+1555' . random_int(2000000, 2999999), 'email' => Str::slug($n) . '@example.com', 'website' => 'https://' . Str::slug($n) . '.com', 'address' => ($i + 10) . ' Main St', 'lat' => 40.7 + $i * 0.01, 'lng' => -74.0 - $i * 0.01, 'rating' => round(3.5 + ($i % 3) * 0.5, 1), 'in_crm' => $i < 2, 'raw' => []]);
        }

        foreach ([['/hi', 'Greeting', 'Hi there! Thanks for reaching out to Demo Co. How can I help?'], ['/hours', 'Hours', 'We are open Mon-Fri 9am-6pm.'], ['/price', 'Pricing', 'Our plans start at $19/mo. Want details?'], ['/ship', 'Shipping', 'We ship worldwide in 3-5 business days.'], ['/bye', 'Closing', 'Thanks for chatting — have a great day!']] as [$sc, $title, $body]) {
            \App\Models\SavedReply::create(['workspace_id' => $ws, 'user_id' => $u, 'shortcut' => $sc, 'title' => $title, 'body' => $body, 'category' => 'General', 'used_count' => random_int(0, 40)]);
        }

        \App\Models\RoutingRule::create(['workspace_id' => $ws, 'name' => 'VIP → Sales team', 'conditions' => [['field' => 'tag', 'op' => 'is', 'value' => 'VIP']], 'actions' => [['type' => 'assign_team', 'value' => 'sales']], 'stop_on_match' => true, 'is_active' => true, 'is_fallback' => false, 'sort' => 0]);
        \App\Models\RoutingRule::create(['workspace_id' => $ws, 'name' => 'Default → Support', 'conditions' => [], 'actions' => [['type' => 'assign_team', 'value' => 'support']], 'stop_on_match' => false, 'is_active' => true, 'is_fallback' => true, 'sort' => 99]);

        \App\Models\SlaPolicy::create(['workspace_id' => $ws, 'name' => 'Standard SLA', 'first_response_minutes' => 30, 'resolution_minutes' => 480, 'pause_when_waiting_on_customer' => true, 'respect_business_hours' => true, 'is_default' => true]);

        $this->report[] = 'misc=ok';
    }
}
