<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic Facebook Pages demo so the channel has something to render
 * on first load — a connected Page, a spread of Messenger DM threads + a post
 * comment thread in the UNIFIED inbox (channel='facebook', same shape
 * FacebookIngestService writes), and a few composed / scheduled Page posts.
 *
 * This is the Facebook analogue of the Instagram / chat demo data. Idempotent:
 * the demo Page is keyed on a fixed page_id, and its conversations + posts are
 * wiped and re-created on re-run so IDs never drift.
 *
 *   php artisan db:seed --class=Database\\Seeders\\FacebookShowcaseSeeder
 */
class FacebookShowcaseSeeder extends Seeder
{
    /** Fixed synthetic Meta Page id for the demo Page (idempotency key). */
    private const DEMO_PAGE_ID = '100000000000901';

    public function run(): void
    {
        // Seed into the workspace the demo admin is ACTUALLY looking at — the
        // current_workspace_id of test@example.com (the primary demo user), else
        // the first user's current workspace, else the lowest-id workspace. This
        // avoids the "I can't see it" trap where the data lands in a different
        // workspace than the one the operator is logged into. Override with:
        //   FB_SEED_WS=5 php artisan db:seed --class=…\\FacebookShowcaseSeeder
        $envWs   = (int) env('FB_SEED_WS', 0);
        $demoUser = \App\Models\User::query()->where('email', 'test@example.com')->first()
            ?: \App\Models\User::query()->orderBy('id')->first();
        $wsId = $envWs
            ?: (int) ($demoUser->current_workspace_id ?? 0)
            ?: (int) (optional(Workspace::query()->orderBy('id')->first())->id ?? 0);

        $ws = $wsId ? Workspace::find($wsId) : null;
        if (! $ws) {
            $this->command?->warn('[FB-SEED] No workspace found — nothing to seed.');

            return;
        }
        $wsId   = (int) $ws->id;
        $userId = (int) ($ws->owner_user_id ?? optional($demoUser)->id ?? 0) ?: null;

        $page = $this->seedPage($wsId, $userId);
        $this->seedInbox($page);
        $this->seedPosts($page, $userId);

        $this->command?->info("[FB-SEED] Facebook demo seeded for workspace #{$wsId} (page {$page->page_id}).");
    }

    /** The one connected demo Page. */
    private function seedPage(int $wsId, ?int $userId): FacebookPage
    {
        return FacebookPage::updateOrCreate(
            ['page_id' => self::DEMO_PAGE_ID],
            [
                'workspace_id'           => $wsId,
                'user_id'                => $userId,
                'name'                   => 'Bloomly Home & Living',
                'category'               => 'Furniture Store',
                'username'               => 'bloomly.living',
                'picture_url'            => 'https://ui-avatars.com/api/?name=Bloomly&background=1877F2&color=fff&size=200',
                // A believable-looking but INERT demo token. Never used for a real
                // Graph call (the demo Page has no live webhook), so it can't leak.
                'access_token'           => 'DEMO-FB-PAGE-TOKEN-'.Str::random(24),
                'token_expires_at'       => null, // page tokens don't expire
                'data_access_expires_at' => now()->addDays(85),
                'scopes'                 => ['pages_show_list', 'pages_messaging', 'pages_manage_posts', 'pages_read_engagement', 'pages_manage_engagement'],
                'tasks'                  => ['MANAGE', 'CREATE_CONTENT', 'MODERATE', 'MESSAGING', 'ANALYZE'],
                'status'                 => 'connected',
                'connect_method'         => 'embedded',
                'fan_count'              => 8421,
                'last_error'             => null,
                'meta_json'              => ['seeded' => true, 'demo' => true],
            ]
        );
    }

    /**
     * Unified-inbox demo threads for this Page — Messenger DMs (one thread per
     * PSID) plus one post-comment thread. Same keys FacebookIngestService uses,
     * so they render identically to live traffic.
     */
    private function seedInbox(FacebookPage $page): void
    {
        $wsId = (int) $page->workspace_id;

        // Wipe prior demo threads for this Page so re-runs never duplicate.
        // NOT scoped by workspace_id: the raw_jid already carries the fixed demo
        // page_id, so this matches only demo rows — and it cleans up any threads
        // left on an OLD workspace when the demo Page is re-targeted to another.
        $priorConvIds = Conversation::query()
            ->where('channel', 'facebook')
            ->where('raw_jid', 'like', 'fb:'.$page->page_id.':%')
            ->pluck('id');
        if ($priorConvIds->isNotEmpty()) {
            InboxMessage::whereIn('conversation_id', $priorConvIds)->delete();
            Conversation::whereIn('id', $priorConvIds)->delete();
        }

        $now = now();

        // Each: [psid, name, avatar, unread, [ [dir, body, mediaType, minutesAgo], ... ] ]
        $dms = [
            ['7100000000001', 'Aisha Rahman', 2, [
                ['in',  'Hi! Is the walnut coffee table still in stock?',            null, 42],
                ['out', 'Hi Aisha! Yes — the walnut finish is in stock and ships in 3–5 days.', null, 40],
                ['in',  'Perfect. Does it come pre-assembled?',                      null, 12],
            ]],
            ['7100000000002', 'Daniel Okoro', 0, [
                ['in',  'Do you deliver to Lagos?',                                  null, 180],
                ['out', 'We do! Delivery to Lagos is free on orders over ₦50,000.',  null, 176],
                ['in',  'Great, I will place my order today. Thanks!',               null, 171],
            ]],
            ['7100000000003', 'Meera Nair', 1, [
                ['in',  'Sent you a photo of my living room for advice 🙂',           'image', 300],
                ['out', 'Love the space! A 2-seater in sage green would fit beautifully.', null, 295],
            ]],
            ['7100000000004', 'Tomás Alvarez', 0, [
                ['in',  '¿Tienen envío internacional?',                              null, 60 * 26],
                ['out', 'Sí — enviamos a toda Latinoamérica. Te comparto los costos por aquí.', null, 60 * 26 - 4],
            ]],
        ];

        foreach ($dms as [$psid, $name, $unread, $msgs]) {
            $last = $now->copy()->subMinutes((int) $msgs[array_key_last($msgs)][3]);
            $conv = Conversation::create([
                'workspace_id'    => $wsId,
                'channel'         => 'facebook',
                'raw_jid'         => 'fb:'.$page->page_id.':'.$psid,
                'title'           => $name,
                'provider'        => 'facebook',
                'origin'          => 'facebook',
                'status'          => 'pending',
                'inbox_status'    => 'open',
                'preview'         => Str::limit((string) end($msgs)[1], 120),
                'unread_count'    => $unread,
                'last_message_at' => $last,
                'last_inbound_at' => $last,
                'contact_digits'  => null,
                'routing_meta'    => ['fb_avatar' => 'https://ui-avatars.com/api/?name='.urlencode($name).'&size=120'],
            ]);

            foreach ($msgs as [$dir, $body, $mediaType, $minAgo]) {
                $when = $now->copy()->subMinutes((int) $minAgo);
                InboxMessage::create([
                    'conversation_id' => $conv->id,
                    'provider'        => 'facebook',
                    'direction'       => $dir,
                    'body'            => $body,
                    'media_type'      => $mediaType,
                    'media_path'      => $mediaType === 'image' ? 'https://picsum.photos/seed/fb'.$psid.'/600/400' : null,
                    'from_number'     => $dir === 'in' ? $psid : null,
                    'status'          => $dir === 'in' ? 'received' : 'sent',
                    'meta'            => ['facebook' => ['message_id' => 'm_demo_'.Str::random(12), 'psid' => $psid, 'kind' => 'dm']],
                    'sent_at'         => $when,
                    'delivered_at'    => $when,
                    'created_at'      => $when,
                    'updated_at'      => $when,
                ]);
            }
        }

        // One post-comment moderation thread (per-commenter key, kind=comment).
        $postId    = self::DEMO_PAGE_ID.'_9007700001';
        $commenter = '7100000000009';
        $cWhen     = $now->copy()->subMinutes(95);
        $cConv = Conversation::create([
            'workspace_id'    => $wsId,
            'channel'         => 'facebook',
            'raw_jid'         => 'fb:'.$page->page_id.':post:'.$postId.':'.$commenter,
            'title'           => 'Grace Lim',
            'provider'        => 'facebook',
            'origin'          => 'facebook',
            'status'          => 'pending',
            'inbox_status'    => 'open',
            'preview'         => 'Is the autumn collection available online?',
            'unread_count'    => 1,
            'last_message_at' => $cWhen,
            'last_inbound_at' => $cWhen,
            'contact_digits'  => null,
        ]);
        InboxMessage::create([
            'conversation_id' => $cConv->id,
            'provider'        => 'facebook',
            'direction'       => 'in',
            'body'            => 'Is the autumn collection available online?',
            'from_number'     => $commenter,
            'status'          => 'received',
            'meta'            => ['facebook' => [
                'message_id' => 'c_demo_'.Str::random(12),
                'comment_id' => 'c_demo_'.Str::random(12),
                'post_id'    => $postId,
                'from_id'    => $commenter,
                'from_name'  => 'Grace Lim',
                'kind'       => 'comment',
            ]],
            'sent_at'         => $cWhen,
            'delivered_at'    => $cWhen,
            'created_at'      => $cWhen,
            'updated_at'      => $cWhen,
        ]);
    }

    /** A few composed / scheduled Page posts for the /facebook/posts list. */
    private function seedPosts(FacebookPage $page, ?int $userId): void
    {
        FacebookPost::query()->where('facebook_page_id', $page->id)->delete();

        $now = now();
        $rows = [
            [
                'type'    => 'photo',
                'status'  => 'published',
                'message' => "New in: the Autumn Warmth collection ☕️🍂\nHandwoven throws + walnut side tables, now live in-store and online.",
                'link'    => null,
                'media'   => ['https://picsum.photos/seed/fbpost1/1080/1080'],
                'fb_post_id'  => self::DEMO_PAGE_ID.'_9007700001',
                'published_at'=> $now->copy()->subDays(2),
                'scheduled'   => null,
            ],
            [
                'type'    => 'link',
                'status'  => 'published',
                'message' => 'Our design guide: 5 ways to warm up a small living room this winter.',
                'link'    => 'https://bloomly.example.com/blog/warm-small-living-rooms',
                'media'   => null,
                'fb_post_id'  => self::DEMO_PAGE_ID.'_9007700002',
                'published_at'=> $now->copy()->subDays(5),
                'scheduled'   => null,
            ],
            [
                'type'    => 'photo',
                'status'  => 'scheduled',
                'message' => "Weekend flash sale 🎉 25% off all lighting.\nSaturday 9am — reply SHOP to get early access.",
                'link'    => null,
                'media'   => ['https://picsum.photos/seed/fbpost3/1080/1350'],
                'fb_post_id'  => null,
                'published_at'=> null,
                'scheduled'   => $now->copy()->addDays(2)->setTime(9, 0),
            ],
        ];

        foreach ($rows as $r) {
            FacebookPost::create([
                'workspace_id'           => (int) $page->workspace_id,
                'facebook_page_id'       => $page->id,
                'user_id'                => $userId,
                'fb_post_id'             => $r['fb_post_id'],
                'type'                   => $r['type'],
                'status'                 => $r['status'],
                'message'                => $r['message'],
                'link'                   => $r['link'],
                'media_json'             => $r['media'],
                'scheduled_publish_time' => $r['scheduled'],
                'published_at'           => $r['published_at'],
                'error'                  => null,
                'meta_json'              => ['seeded' => true],
            ]);
        }
    }
}
