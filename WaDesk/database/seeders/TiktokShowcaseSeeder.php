<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\TiktokAccount;
use App\Models\TiktokPost;
use App\Models\TiktokShop;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic TikTok demo so the channel renders on first load — a
 * connected account, a spread of DM threads in the UNIFIED inbox
 * (channel='tiktok', same shape TiktokIngestService writes), a few posts, and a
 * connected TikTok Shop for the Integrations page.
 *
 * The TikTok analogue of FacebookShowcaseSeeder. Idempotent: keyed on a fixed
 * open_id; conversations + posts are wiped and re-created on re-run.
 *
 * The demo account's region is set to a MESSAGING-SUPPORTED market (ID) and
 * carries inert demo business creds, so the DM inbox + flow runtime are usable
 * in the demo (all real API calls fail soft — nothing leaks).
 *
 *   php artisan db:seed --class=Database\\Seeders\\TiktokShowcaseSeeder
 */
class TiktokShowcaseSeeder extends Seeder
{
    private const DEMO_OPEN_ID = 'tt_demo_open_9007001';
    private const DEMO_SHOP_ID = 'tt_demo_shop_5501';

    public function run(): void
    {
        $envWs   = (int) env('TT_SEED_WS', 0);
        $demoUser = \App\Models\User::query()->where('email', 'test@example.com')->first()
            ?: \App\Models\User::query()->orderBy('id')->first();
        $wsId = $envWs
            ?: (int) ($demoUser->current_workspace_id ?? 0)
            ?: (int) (optional(Workspace::query()->orderBy('id')->first())->id ?? 0);

        $ws = $wsId ? Workspace::find($wsId) : null;
        if (! $ws) {
            $this->command?->warn('[TT-SEED] No workspace found — nothing to seed.');

            return;
        }
        $wsId   = (int) $ws->id;
        $userId = (int) ($ws->owner_user_id ?? optional($demoUser)->id ?? 0) ?: null;

        // Make the demo self-contained: enable the TikTok channel + Shop with
        // inert demo credentials ONLY when the admin hasn't configured real ones,
        // so a fresh install renders the whole channel without a manual setup step
        // (and a real deployment's keys are never overwritten).
        $ensure = function (string $key, $val) {
            if (trim((string) \App\Models\SystemSetting::get($key, '')) === '') {
                \App\Models\SystemSetting::set($key, $val, is_bool($val) ? 'bool' : 'string', 'TikTok demo (seeded).');
            }
        };
        $ensure('tiktok_client_key', 'demo_tt_client_key');
        $ensure('tiktok_client_secret', 'demo_tt_client_secret');
        \App\Models\SystemSetting::set('tiktok_enabled', true, 'bool', 'TikTok demo (seeded).');
        $ensure('tiktok_shop_app_key', 'demo_tts_app_key');
        $ensure('tiktok_shop_app_secret', 'demo_tts_app_secret');
        \App\Models\SystemSetting::set('tiktok_shop_enabled', true, 'bool', 'TikTok Shop demo (seeded).');

        $account = $this->seedAccount($wsId, $userId);
        $this->seedInbox($account);
        $this->seedPosts($account, $userId);
        $this->seedShop($wsId, $userId);

        $this->command?->info("[TT-SEED] TikTok demo seeded for workspace #{$wsId} (account {$account->open_id}).");
    }

    /** The one connected demo account. Region ID = messaging-supported. */
    private function seedAccount(int $wsId, ?int $userId): TiktokAccount
    {
        return TiktokAccount::updateOrCreate(
            ['workspace_id' => $wsId, 'open_id' => self::DEMO_OPEN_ID],
            [
                'user_id'            => $userId,
                'union_id'           => 'tt_demo_union_'.Str::random(10),
                'display_name'       => 'Bloomly Living',
                'username'           => 'bloomly.living',
                'avatar_url'         => 'https://ui-avatars.com/api/?name=Bloomly&background=010101&color=fff&size=200',
                'bio'                => 'Cosy homes, handmade 🌿 | New drops every Friday',
                'is_verified'        => true,
                'follower_count'     => 128400,
                'following_count'    => 312,
                'likes_count'        => 2140000,
                'video_count'        => 84,
                // Inert demo tokens — never used for a real call (no live account).
                'access_token'       => 'DEMO-TT-ACCESS-'.Str::random(24),
                'refresh_token'      => 'DEMO-TT-REFRESH-'.Str::random(24),
                'token_expires_at'   => now()->addHours(23),
                'refresh_expires_at' => now()->addDays(360),
                'scopes'             => ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.list', 'video.upload'],
                'status'             => 'connected',
                'connect_method'     => 'oauth',
                'last_error'         => null,
                // Business-Messaging creds + region so the DM inbox + flow work in demo.
                'meta_json'          => ['seeded' => true, 'demo' => true, 'business' => [
                    'access_token' => 'DEMO-TT-BM-'.Str::random(20),
                    'business_id'  => 'tt_demo_biz_5501',
                    'region'       => 'ID',
                ]],
            ]
        );
    }

    /** Unified-inbox demo DM threads (one per conversation). */
    private function seedInbox(TiktokAccount $account): void
    {
        $wsId = (int) $account->workspace_id;

        $priorConvIds = Conversation::query()
            ->where('channel', 'tiktok')
            ->where('raw_jid', 'like', 'tt:'.$account->open_id.':%')
            ->pluck('id');
        if ($priorConvIds->isNotEmpty()) {
            InboxMessage::whereIn('conversation_id', $priorConvIds)->delete();
            Conversation::whereIn('id', $priorConvIds)->delete();
        }

        $now = now();

        // [convId, senderId, name, unread, [ [dir, body, mediaType, minutesAgo], ... ]]
        $dms = [
            ['conv_tt_1001', 'ttu_88001', 'Priya Sharma', 2, [
                ['in',  'Saw your rattan chair on TikTok 😍 is it still available?', null, 38],
                ['out', 'Hi Priya! Yes it is — the natural rattan is back in stock this week.', null, 35],
                ['in',  'Yay! Do you ship to Bandung?', null, 9],
            ]],
            ['conv_tt_1002', 'ttu_88002', 'Reza Pratama', 0, [
                ['in',  'Berapa harga meja kayu jati yang di video?', null, 150],
                ['out', 'Halo Reza! Meja jati Rp 1.850.000, gratis ongkir se-Jawa 🚚', null, 146],
                ['in',  'Oke saya mau pesan 1 ya', null, 140],
            ]],
            ['conv_tt_1003', 'ttu_88003', 'Maya Tan', 1, [
                ['in',  'Do you have the beige cushion cover in the last reel?', 'image', 260],
                ['out', 'We do! It comes in beige, sage and clay — which size do you need?', null, 255],
            ]],
            ['conv_tt_1004', 'ttu_88004', 'Arif Hidayat', 0, [
                ['in',  'promo minggu ini apa aja kak?', null, 60 * 20],
                ['out', 'Minggu ini diskon 20% semua lampu 💡 pakai kode TIKTOK20 ya!', null, 60 * 20 - 3],
            ]],
        ];

        foreach ($dms as [$convId, $sender, $name, $unread, $msgs]) {
            $last = $now->copy()->subMinutes((int) $msgs[array_key_last($msgs)][3]);
            $conv = Conversation::create([
                'workspace_id'    => $wsId,
                'channel'         => 'tiktok',
                'raw_jid'         => 'tt:'.$account->open_id.':'.$convId,
                'title'           => $name,
                'provider'        => 'tiktok',
                'origin'          => 'tiktok',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'preview'         => Str::limit((string) end($msgs)[1], 120),
                'unread_count'    => $unread,
                'last_message_at' => $last,
                'last_inbound_at' => $last,
                'contact_digits'  => null,
                'routing_meta'    => ['tt_avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&size=120'],
            ]);

            foreach ($msgs as [$dir, $body, $mediaType, $minAgo]) {
                $when = $now->copy()->subMinutes((int) $minAgo);
                InboxMessage::create([
                    'conversation_id' => $conv->id,
                    'provider'        => 'tiktok',
                    'direction'       => $dir,
                    'body'            => $body,
                    'media_type'      => $mediaType,
                    'media_path'      => $mediaType === 'image' ? 'https://picsum.photos/seed/tt'.$sender.'/600/400' : null,
                    'from_number'     => $dir === 'in' ? $sender : null,
                    'status'          => $dir === 'in' ? 'received' : 'sent',
                    'meta'            => ['tiktok' => ['message_id' => 'm_demo_'.Str::random(12), 'conversation_id' => $convId, 'sender_id' => $sender]],
                    'sent_at'         => $when,
                    'delivered_at'    => $when,
                    'created_at'      => $when,
                    'updated_at'      => $when,
                ]);
            }
        }
    }

    /** A few posts for the /tiktok/posts list. */
    private function seedPosts(TiktokAccount $account, ?int $userId): void
    {
        TiktokPost::query()->where('tiktok_account_id', $account->id)->delete();

        $now = now();
        $rows = [
            ['type' => 'video', 'status' => 'published', 'caption' => 'How we style a small living room 🌿 #homedecor #cozyhome',
             'video_url' => 'https://cdn.example.com/demo/livingroom.mp4', 'publish_id' => 'pub_demo_'.Str::random(10), 'tiktok_post_id' => '7360000000000001', 'published_at' => $now->copy()->subDays(1)],
            ['type' => 'video', 'status' => 'processing', 'caption' => 'New rattan chair restock! Reply for the link 🪑',
             'video_url' => 'https://cdn.example.com/demo/rattan.mp4', 'publish_id' => 'pub_demo_'.Str::random(10), 'tiktok_post_id' => null, 'published_at' => null],
            ['type' => 'video', 'status' => 'failed', 'caption' => 'Weekend sale teaser',
             'video_url' => 'https://notpublic.local/clip.mp4', 'publish_id' => null, 'tiktok_post_id' => null, 'published_at' => null, 'error' => 'The video URL must be a public HTTPS link with a verified domain.'],
        ];

        foreach ($rows as $r) {
            TiktokPost::create([
                'workspace_id'      => (int) $account->workspace_id,
                'tiktok_account_id' => $account->id,
                'user_id'           => $userId,
                'type'              => $r['type'],
                'status'            => $r['status'],
                'caption'           => $r['caption'],
                'media_json'        => ['video_url' => $r['video_url'], 'target' => 'inbox'],
                'publish_id'        => $r['publish_id'],
                'tiktok_post_id'    => $r['tiktok_post_id'],
                'published_at'      => $r['published_at'],
                'error'             => $r['error'] ?? null,
                'meta_json'         => ['seeded' => true],
            ]);
        }
    }

    /** Connected demo TikTok Shops for the Integrations page (two, to exercise the switcher). */
    private function seedShop(int $wsId, ?int $userId): void
    {
        // Second shop — a different region + smaller catalog, so the multi-shop
        // switcher has something to switch to.
        TiktokShop::updateOrCreate(
            ['workspace_id' => $wsId, 'shop_id' => 'tt_demo_shop_5502'],
            [
                'user_id'     => $userId,
                'shop_name'   => 'Bloomly SG Outlet',
                'shop_cipher' => 'DEMO-CIPHER-SG-'.Str::random(14),
                'shop_code'   => 'BLOOMLYSG',
                'region'      => 'SG',
                'seller_name' => 'Bloomly Living',
                'access_token'       => 'DEMO-TTS-ACCESS-'.Str::random(20),
                'refresh_token'      => 'DEMO-TTS-REFRESH-'.Str::random(20),
                'token_expires_at'   => now()->addHours(6),
                'refresh_expires_at' => now()->addDays(30),
                'status'             => 'connected',
                'last_error'         => null,
                'meta_json'          => ['seeded' => true, 'demo' => true,
                    'stats' => ['orders' => 34, 'revenue' => 'S$ 8.2K', 'products' => 12, 'unread_messages' => 1],
                    'recent_orders' => [
                        ['id' => '577000000001', 'buyer' => 'Wei L.',  'item' => 'Oak Bedside Table',    'qty' => 1, 'amount' => 'S$ 129', 'status' => 'To ship',   'when' => '1h ago'],
                        ['id' => '577000000002', 'buyer' => 'Siti R.', 'item' => 'Woven Wall Basket Set', 'qty' => 2, 'amount' => 'S$ 58',  'status' => 'Delivered', 'when' => '5h ago'],
                    ],
                    'recent_messages' => [
                        ['buyer' => 'Wei L.', 'text' => 'Do you deliver to Jurong East?', 'when' => '20m ago', 'unread' => true],
                    ],
                    'products' => [
                        ['name' => 'Oak Bedside Table',     'price' => 'S$ 129', 'stock' => 9,  'sold' => 40, 'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttsg1/400/400'],
                        ['name' => 'Woven Wall Basket Set', 'price' => 'S$ 29',  'stock' => 22, 'sold' => 88, 'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttsg2/400/400'],
                        ['name' => 'Ceramic Table Lamp',    'price' => 'S$ 35',  'stock' => 0,  'sold' => 61, 'status' => 'Out of stock', 'img' => 'https://picsum.photos/seed/ttsg3/400/400'],
                    ],
                ],
            ]
        );

        TiktokShop::updateOrCreate(
            ['workspace_id' => $wsId, 'shop_id' => self::DEMO_SHOP_ID],
            [
                'user_id'            => $userId,
                'shop_name'          => 'Bloomly Living Store',
                'shop_cipher'        => 'DEMO-CIPHER-'.Str::random(18),
                'shop_code'          => 'BLOOMLY',
                'region'             => 'ID',
                'seller_name'        => 'Bloomly Living',
                'access_token'       => 'DEMO-TTS-ACCESS-'.Str::random(20),
                'refresh_token'      => 'DEMO-TTS-REFRESH-'.Str::random(20),
                'token_expires_at'   => now()->addHours(6),
                'refresh_expires_at' => now()->addDays(30),
                'status'             => 'connected',
                'last_error'         => null,
                'meta_json'          => ['seeded' => true, 'demo' => true,
                    'stats' => ['orders' => 128, 'revenue' => 'Rp 42.8M', 'products' => 36, 'unread_messages' => 3],
                    'recent_orders' => [
                        ['id' => '576000000001', 'buyer' => 'Priya S.',  'item' => 'Rattan Lounge Chair',   'qty' => 1, 'amount' => 'Rp 1,850,000', 'status' => 'To ship',   'when' => '2h ago'],
                        ['id' => '576000000002', 'buyer' => 'Reza P.',   'item' => 'Walnut Coffee Table',    'qty' => 1, 'amount' => 'Rp 2,400,000', 'status' => 'Shipped',   'when' => '6h ago'],
                        ['id' => '576000000003', 'buyer' => 'Maya T.',   'item' => 'Linen Cushion (x3)',     'qty' => 3, 'amount' => 'Rp 450,000',   'status' => 'Delivered', 'when' => '1d ago'],
                        ['id' => '576000000004', 'buyer' => 'Arif H.',   'item' => 'Ceramic Table Lamp',     'qty' => 2, 'amount' => 'Rp 780,000',   'status' => 'To ship',   'when' => '1d ago'],
                    ],
                    'recent_messages' => [
                        ['buyer' => 'Reza P.', 'text' => 'Is the walnut table still available in dark finish?', 'when' => '15m ago', 'unread' => true],
                        ['buyer' => 'Priya S.', 'text' => 'Can you ship the chair before Friday?',              'when' => '48m ago', 'unread' => true],
                        ['buyer' => 'Nadia K.', 'text' => 'Thanks! Order received 🙏',                          'when' => '3h ago',  'unread' => false],
                    ],
                    'products' => [
                        ['name' => 'Rattan Lounge Chair',   'price' => 'Rp 1,850,000', 'stock' => 12,  'sold' => 340, 'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttp1/400/400'],
                        ['name' => 'Walnut Coffee Table',   'price' => 'Rp 2,400,000', 'stock' => 6,   'sold' => 128, 'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttp2/400/400'],
                        ['name' => 'Linen Cushion Cover',   'price' => 'Rp 150,000',   'stock' => 240, 'sold' => 1820,'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttp3/400/400'],
                        ['name' => 'Ceramic Table Lamp',    'price' => 'Rp 390,000',   'stock' => 0,   'sold' => 512, 'status' => 'Out of stock', 'img' => 'https://picsum.photos/seed/ttp4/400/400'],
                        ['name' => 'Woven Wall Basket Set', 'price' => 'Rp 320,000',   'stock' => 58,  'sold' => 274, 'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttp5/400/400'],
                        ['name' => 'Oak Bedside Table',     'price' => 'Rp 1,150,000', 'stock' => 9,   'sold' => 96,  'status' => 'Active',       'img' => 'https://picsum.photos/seed/ttp6/400/400'],
                    ],
                ],
            ]
        );
    }
}
