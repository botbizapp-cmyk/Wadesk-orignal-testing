<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Flow;
use App\Models\InboxMessage;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Models\TelegramAccount;
use App\Models\TelegramBot;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic Telegram demo so the channel renders on first load — the
 * channel turned on with the plan feature granted, a connected bot, a connected
 * user account (for the in-app bot maker), a spread of DM + group threads in the
 * UNIFIED inbox (channel='telegram', raw_jid 'tg:<botRowId>:<chatId>' — exactly
 * what TelegramWebhookController writes), a working keyword flow, and a draft
 * broadcast with its audience.
 *
 * The Telegram analogue of TiktokShowcaseSeeder. Idempotent: keyed on a fixed
 * bot id; threads/flow/broadcast are wiped and re-created on re-run. Every real
 * API call fails soft (the demo token/session are inert) — nothing leaks.
 *
 *   php artisan db:seed --class=Database\\Seeders\\TelegramShowcaseSeeder
 */
class TelegramShowcaseSeeder extends Seeder
{
    private const DEMO_BOT_ID     = '7700100200';   // Telegram's own bot id (display only)
    private const DEMO_BOT_USER   = 'bloomly_support_bot';
    private const DEMO_TG_USER_ID = 'tg_demo_user_88500';
    private const FLOW_NAME       = 'Telegram — Welcome & Menu (demo)';

    public function run(): void
    {
        $envWs   = (int) env('TG_SEED_WS', 0);
        $demoUser = User::query()->where('email', 'test@example.com')->first()
            ?: User::query()->orderBy('id')->first();
        $wsId = $envWs
            ?: (int) ($demoUser->current_workspace_id ?? 0)
            ?: (int) (optional(Workspace::query()->orderBy('id')->first())->id ?? 0);

        $ws = $wsId ? Workspace::find($wsId) : null;
        if (! $ws) {
            $this->command?->warn('[TG-SEED] No workspace found — nothing to seed.');

            return;
        }
        $wsId   = (int) $ws->id;
        $userId = (int) ($ws->owner_user_id ?? optional($demoUser)->id ?? 0) ?: null;

        // Make the demo self-contained: enable the channel and grant the plan
        // feature so the nav item + /telegram page are reachable. Demo api_id/hash
        // are only set when the admin hasn't configured real ones (a real
        // deployment's keys are never overwritten).
        SystemSetting::set('telegram_enabled', true, 'bool', 'Telegram demo (seeded).');
        $ensure = function (string $key, $val) {
            if (trim((string) SystemSetting::get($key, '')) === '') {
                SystemSetting::set($key, $val, 'string', 'Telegram demo (seeded).');
            }
        };
        $ensure('telegram_api_id', '1234567');
        $ensure('telegram_api_hash', 'demo_tg_api_hash_'.Str::random(16));

        // Grant the plan feature on every package so the demo workspace — whatever
        // plan it is on — can open the channel.
        if (Package::query()->exists()) {
            $update = ['access_telegram' => true, 'telegram_broadcasts' => true];
            if (\Illuminate\Support\Facades\Schema::hasColumn('packages', 'telegram_bots_limit')) {
                $update['telegram_bots_limit'] = 5;
            }
            Package::query()->update($update);
        }

        $bot     = $this->seedBot($wsId, $userId);
        $this->seedAccount($wsId, $userId);
        $this->seedInbox($bot);
        $this->seedFlow($wsId, $userId, $bot);
        $this->seedPaymentFlow($wsId, $userId, $bot);
        $this->seedTemplate($wsId, $userId);
        $this->seedAutoReply($wsId, $userId, $bot);
        $this->seedBroadcast($bot, $userId);

        $this->command?->info("[TG-SEED] Telegram demo seeded for workspace #{$wsId} (bot @{$bot->bot_username}).");
    }

    /** A local reusable Telegram template (with buttons) for the inbox composer. */
    private function seedTemplate(int $wsId, ?int $userId): void
    {
        \App\Models\WaTemplate::updateOrCreate(
            ['workspace_id' => $wsId, 'channel' => 'telegram', 'template_name' => 'tg_welcome_offer'],
            [
                'user_id'       => $userId,
                'template_body' => "Hi {{name}}! 🌿 Thanks for messaging Bloomly. Here's 10% off your first order with code TG10.",
                'category'      => 'marketing',
                'language'      => 'en',
                'status'        => 'approved',
                'buttons'       => [
                    ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://bloomly.example/shop'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Talk to us'],
                ],
            ]
        );
    }

    /** The one connected demo bot (inert token — real sends fail soft). */
    private function seedBot(int $wsId, ?int $userId): TelegramBot
    {
        [$webhookToken, $secretToken] = TelegramBot::freshTokens();

        return TelegramBot::updateOrCreate(
            ['workspace_id' => $wsId, 'bot_id' => self::DEMO_BOT_ID],
            [
                'connected_by'   => $userId,
                'bot_token'      => self::DEMO_BOT_ID.':DEMO-'.Str::random(30),
                'bot_username'   => self::DEMO_BOT_USER,
                'bot_name'       => 'Bloomly Support',
                'webhook_token'  => $webhookToken,
                'secret_token'   => $secretToken,
                'active'         => true,
                'connected_at'   => now()->subDays(3),
                'last_inbound_at' => now()->subMinutes(9),
                'last_error'     => null,
            ]
        );
    }

    /** A connected user account so the in-app bot maker shows its "signed in" UI. */
    private function seedAccount(int $wsId, ?int $userId): TelegramAccount
    {
        return TelegramAccount::updateOrCreate(
            ['workspace_id' => $wsId, 'tg_user_id' => self::DEMO_TG_USER_ID],
            [
                'connected_by' => $userId,
                'session'      => 'DEMO-TG-SESSION-'.Str::random(40),
                'username'     => 'bloomly_ops',
                'first_name'   => 'Bloomly Ops',
                'phone'        => '+6281200000000',
                'last_error'   => null,
                'connected_at' => now()->subDays(3),
            ]
        );
    }

    /** Unified-inbox demo threads (private DMs + one group), keyed to this bot. */
    private function seedInbox(TelegramBot $bot): void
    {
        $wsId   = (int) $bot->workspace_id;
        $prefix = 'tg:'.$bot->id.':';

        $priorConvIds = Conversation::query()
            ->where('channel', 'telegram')
            ->where('raw_jid', 'like', $prefix.'%')
            ->pluck('id');
        if ($priorConvIds->isNotEmpty()) {
            InboxMessage::whereIn('conversation_id', $priorConvIds)->delete();
            Conversation::whereIn('id', $priorConvIds)->delete();
        }

        $now = now();

        // [chatId, name, kind, unread, [ [dir, body, mediaType, minutesAgo], ... ]]
        $threads = [
            ['588001', 'Priya Sharma', 'private', 2, [
                ['in',  'Hi! Is the rattan lounge chair still in stock?', null, 40],
                ['out', 'Hi Priya! Yes — the natural rattan restocked this week 🌿', null, 36],
                ['in',  'Perfect, do you deliver to Bandung?', null, 9],
            ]],
            ['588002', 'Reza Pratama', 'private', 0, [
                ['in',  'Berapa harga meja kopi walnut kak?', null, 150],
                ['out', 'Halo Reza! Meja walnut Rp 2.400.000, gratis ongkir se-Jawa 🚚', null, 146],
                ['in',  'Oke saya pesan 1 ya', null, 140],
            ]],
            ['588003', 'Maya Tan', 'private', 1, [
                ['in',  'Do you have the beige cushion cover?', 'image', 260],
                ['out', 'We do! Beige, sage and clay — which size do you need?', null, 255],
            ]],
            ['-1001999888', 'Bloomly VIP Buyers', 'group', 3, [
                ['in',  'When is the next Friday drop? 🔥', null, 70],
                ['out', 'This Friday 7pm WIB — lamps + baskets, VIP early access 💡', null, 66],
                ['in',  'Take my money 😍', null, 12],
            ]],
        ];

        foreach ($threads as [$chatId, $name, $kind, $unread, $msgs]) {
            $lastMinAgo = (int) $msgs[array_key_last($msgs)][3];
            $last = $now->copy()->subMinutes($lastMinAgo);

            $conv = Conversation::create([
                'workspace_id'    => $wsId,
                'channel'         => 'telegram',
                'raw_jid'         => $prefix.$chatId,
                'title'           => $name,
                'provider'        => 'telegram',
                'origin'          => 'telegram',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'preview'         => Str::limit((string) $msgs[array_key_last($msgs)][1], 120),
                'unread_count'    => $unread,
                'last_message_at' => $last,
                'last_inbound_at' => $last,
                'contact_digits'  => null,
                'routing_meta'    => [
                    'tg_kind'   => $kind,
                    'tg_avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=229ED9&color=fff&size=120',
                ],
            ]);

            foreach ($msgs as [$dir, $body, $mediaType, $minAgo]) {
                $when = $now->copy()->subMinutes((int) $minAgo);
                InboxMessage::create([
                    'conversation_id' => $conv->id,
                    'provider'        => 'telegram',
                    'direction'       => $dir,
                    'body'            => $body,
                    'media_type'      => $mediaType,
                    'media_path'      => $mediaType === 'image' ? 'https://picsum.photos/seed/tg'.$chatId.'/600/400' : null,
                    'from_number'     => $dir === 'in' ? $chatId : null,
                    'status'          => $dir === 'in' ? 'received' : 'sent',
                    'meta'            => ['telegram' => ['message_id' => 'm_demo_'.Str::random(10), 'chat_id' => $chatId, 'kind' => $kind]],
                    'sent_at'         => $when,
                    'delivered_at'    => $when,
                    'created_at'      => $when,
                    'updated_at'      => $when,
                ]);
            }
        }
    }

    /** A working keyword flow bound to the demo bot (LIVE rule — fires on 'menu'). */
    private function seedFlow(int $wsId, ?int $userId, TelegramBot $bot): void
    {
        foreach (Flow::where('workspace_id', $wsId)->where('flow_type', 'telegram')->get() as $existing) {
            if ((string) $existing->flow_name === self::FLOW_NAME) {
                $existing->forceDelete();
            }
        }

        $graph = $this->flowGraph();

        $flow = new Flow();
        $flow->user_id           = $userId;
        $flow->workspace_id      = $wsId;
        $flow->flow_type         = 'telegram';
        $flow->provider          = 'telegram';
        $flow->flow_name         = self::FLOW_NAME;
        $flow->category          = 'Showcase';
        $flow->flow_data         = json_encode($graph, JSON_UNESCAPED_SLASHES);
        $flow->trigger_kind      = 'keyword';
        $flow->trigger_keywords  = 'menu, start, hi';
        $flow->trigger_device_id = $bot->id;   // binds the keyword rule to this bot
        $flow->is_published      = true;
        $flow->is_active         = true;
        $flow->save();

        if (method_exists($flow, 'saveFlowFile')) {
            $flow->saveFlowFile($graph);
        }
    }

    /** A block-until-paid flow: trigger → invoice (wait) → confirm → end. */
    private function seedPaymentFlow(int $wsId, ?int $userId, TelegramBot $bot): void
    {
        $name = 'Telegram — Order & Pay (demo)';
        foreach (Flow::where('workspace_id', $wsId)->where('flow_type', 'telegram')->get() as $existing) {
            if ((string) $existing->flow_name === $name) {
                $existing->forceDelete();
            }
        }

        $node = fn (int $i, string $type, string $id, array $data): array => [
            'id' => $id, 'type' => $type,
            'x' => 80 + ($i % 5) * 300, 'y' => 60 + intdiv($i, 5) * 180, 'data' => $data,
        ];
        $edge = function (string $from, ?string $handle, string $to): array {
            $e = ['id' => 'e_'.$from.'_'.($handle ?: 'out').'_'.$to, 'source' => $from, 'target' => $to];
            if ($handle !== null) { $e['sourceHandle'] = $handle; }
            return $e;
        };

        $i = 0; $n = [];
        $n[] = $node($i++, 'trigger', 'trigger', ['kind' => 'keyword', 'keywords' => 'buy, order, pay', 'keywordMode' => 'keywords', 'channel' => 'telegram']);
        $n[] = $node($i++, 'message', 'intro', ['text' => "Great choice {{name}}! Here's your invoice — tap Pay to complete your order 🌿"]);
        $n[] = $node($i++, 'tg_payment', 'invoice', [
            'mode' => 'invoice', 'title' => 'Bloomly order', 'description' => 'Rattan Lounge Chair',
            'label' => 'Total', 'currency' => 'USD', 'amount' => '49.00', 'payload' => '', 'wait' => true,
        ]);
        $n[] = $node($i++, 'message', 'confirmed', ['text' => "✅ Payment of {{paid_amount}} {{paid_currency}} received! Your order is confirmed and ships within 2 days. Thank you 💚"]);
        $n[] = $node($i++, 'end', 'end', ['label' => 'End']);

        $e = [];
        $e[] = $edge('trigger', 'out', 'intro');
        $e[] = $edge('intro', 'out', 'invoice');
        $e[] = $edge('invoice', 'out', 'confirmed');   // only reached AFTER payment (wait=true)
        $e[] = $edge('confirmed', 'out', 'end');
        $graph = ['flowNodes' => $n, 'flowEdges' => $e, 'vars' => (object) []];

        $flow = new Flow();
        $flow->user_id           = $userId;
        $flow->workspace_id      = $wsId;
        $flow->flow_type         = 'telegram';
        $flow->provider          = 'telegram';
        $flow->flow_name         = $name;
        $flow->category          = 'Showcase';
        $flow->flow_data         = json_encode($graph, JSON_UNESCAPED_SLASHES);
        $flow->trigger_kind      = 'keyword';
        $flow->trigger_keywords  = 'buy, order, pay';
        $flow->trigger_device_id = $bot->id;
        $flow->is_published      = true;
        $flow->is_active         = true;
        $flow->save();
        if (method_exists($flow, 'saveFlowFile')) {
            $flow->saveFlowFile($graph);
        }
    }

    /** A live Telegram keyword auto-reply (provider='telegram', bound to the bot). */
    private function seedAutoReply(int $wsId, ?int $userId, TelegramBot $bot): void
    {
        $rule = \App\Models\KeywordReply::updateOrCreate(
            ['workspace_id' => $wsId, 'provider' => 'telegram', 'device_id' => $bot->id, 'keyword' => 'hours'],
            [
                'user_id'         => $userId,
                'matching_method' => 'contains',
                'reply_type'      => 'custom',
                'message_type'    => 'text',
                'status'          => true,
                'is_flow_trigger' => false,
                'trigger_type'    => 'keyword',
            ]
        );
        \App\Models\KeywordReplyContent::updateOrCreate(
            ['keyword_reply_id' => $rule->id, 'variant_role' => 'primary'],
            ['content_type' => 'text', 'content' => "We're open Mon–Sat, 9am–7pm 🌿 Send \"menu\" to see this week's drops!", 'is_selected' => true, 'sort_order' => 0]
        );
    }

    /** Trigger → message → buttons → (AI / ask+condition) → end. Every port reachable. */
    private function flowGraph(): array
    {
        $node = function (int $i, string $type, string $id, array $data): array {
            $perRow = 5; $dx = 300; $dy = 180; $x0 = 80; $y0 = 60;
            return [
                'id'   => $id,
                'type' => $type,
                'x'    => $x0 + ($i % $perRow) * $dx,
                'y'    => $y0 + intdiv($i, $perRow) * $dy,
                'data' => $data,
            ];
        };
        $edge = function (string $from, ?string $handle, string $to): array {
            $e = ['id' => 'e_'.$from.'_'.($handle ?: 'out').'_'.$to, 'source' => $from, 'target' => $to];
            if ($handle !== null) $e['sourceHandle'] = $handle;
            return $e;
        };

        $i = 0; $n = [];
        $n[] = $node($i++, 'trigger', 'trigger', ['kind' => 'keyword', 'keywords' => 'menu, start, hi', 'keywordMode' => 'keywords', 'channel' => 'telegram']);
        $n[] = $node($i++, 'message', 'welcome', ['text' => "Hi {{name}}! 👋 Welcome to Bloomly Living. How can we help?"]);
        $n[] = $node($i++, 'buttons', 'menu', ['prompt' => 'Pick one:', 'options' => ['See products', 'Talk to us', 'Get updates'], 'var' => 'choice']);
        $n[] = $node($i++, 'message', 'products', ['text' => "🌿 New this week: Rattan Lounge Chair, Walnut Coffee Table, Linen Cushions. Reply with a name for the link!"]);
        $n[] = $node($i++, 'ai', 'ai', [
            'model' => 'gpt-4o-mini', 'prompt' => 'You are Bloomly Living support. Answer helpfully and briefly.',
            'save' => 'reply', 'assistant' => 0, 'extract' => false, 'silent' => false,
            'conversational' => true, 'exit_keyword' => 'bye', 'fields' => '',
        ]);
        $n[] = $node($i++, 'ask', 'ask_email', ['prompt' => 'Drop your email for Friday drops:', 'var' => 'email', 'options' => []]);
        $n[] = $node($i++, 'condition', 'valid_email', ['conditions' => [['variable' => 'email', 'operator' => 'contains', 'value' => '@']], 'operators' => []]);
        $n[] = $node($i++, 'message', 'subscribed', ['text' => '🎉 Done! We saved {{email}} — see you Friday 7pm WIB.']);
        $n[] = $node($i++, 'message', 'bad_email', ['text' => "That doesn't look like an email — send it once more?"]);
        $n[] = $node($i++, 'end', 'end', ['label' => 'End']);

        $e = [];
        $e[] = $edge('trigger', 'out', 'welcome');
        $e[] = $edge('welcome', 'out', 'menu');
        $e[] = $edge('menu', 'p0', 'products');     // See products
        $e[] = $edge('menu', 'p1', 'ai');           // Talk to us
        $e[] = $edge('menu', 'p2', 'ask_email');    // Get updates
        $e[] = $edge('products', 'out', 'end');
        $e[] = $edge('ai', 'out', 'end');
        $e[] = $edge('ask_email', 'out', 'valid_email');
        $e[] = $edge('valid_email', 'yes', 'subscribed');
        $e[] = $edge('valid_email', 'no', 'bad_email');
        $e[] = $edge('subscribed', 'out', 'end');
        $e[] = $edge('bad_email', 'out', 'ask_email');

        return ['flowNodes' => $n, 'flowEdges' => $e, 'vars' => (object) []];
    }

    /** A draft broadcast whose audience is the seeded private threads. */
    private function seedBroadcast(TelegramBot $bot, ?int $userId): void
    {
        TelegramBroadcast::where('telegram_bot_id', $bot->id)
            ->where('name', 'Friday Drop announcement (demo)')
            ->get()->each(function ($b) {
                TelegramBroadcastRecipient::where('telegram_broadcast_id', $b->id)->delete();
                $b->delete();
            });

        $recipients = Conversation::query()
            ->where('channel', 'telegram')
            ->where('raw_jid', 'like', 'tg:'.$bot->id.':%')
            ->get(['id', 'title', 'raw_jid']);

        // Attach the demo template's buttons so the broadcast showcases the same
        // tappable keyboard the inbox template send builds.
        $tpl = \App\Models\WaTemplate::where('workspace_id', (int) $bot->workspace_id)
            ->where('channel', 'telegram')->whereNotNull('buttons')->orderByDesc('id')->first();

        $bcast = TelegramBroadcast::create([
            'workspace_id'   => (int) $bot->workspace_id,
            'telegram_bot_id' => $bot->id,
            'user_id'        => $userId,
            'name'           => 'Friday Drop announcement (demo)',
            'template_id'    => $tpl?->id,
            'body'           => "🌿 New drop this Friday 7pm WIB! Lamps, baskets & more — VIP early access for you, {{name}}.",
            'buttons'        => ($tpl && is_array($tpl->buttons)) ? array_values($tpl->buttons) : null,
            'status'         => TelegramBroadcast::STATUS_DRAFT,
            'total'          => $recipients->count(),
        ]);

        foreach ($recipients as $c) {
            $parts  = explode(':', (string) $c->raw_jid);
            $chatId = end($parts);
            TelegramBroadcastRecipient::create([
                'telegram_broadcast_id' => $bcast->id,
                'chat_id'               => $chatId,
                'title'                 => $c->title,
                'kind'                  => str_starts_with((string) $chatId, '-')
                    ? TelegramBroadcastRecipient::KIND_GROUP
                    : TelegramBroadcastRecipient::KIND_PRIVATE,
                'conversation_id'       => $c->id,
                'status'                => TelegramBroadcastRecipient::STATUS_PENDING,
            ]);
        }
    }
}
