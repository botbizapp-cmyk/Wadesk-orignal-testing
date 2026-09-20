<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\BlogPost;
use App\Models\ContactCustomField;
use App\Models\LegalPage;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PricingFaq;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-app content + utility surface. Bridges the Flutter app's legacy CMS /
 * profile contracts (PagesController / BlogController / FAQController index,
 * ProfileController::banner / notifications, AffiliateController::generateLink,
 * WhatsAppMessageApiController::getUserCredits, BlogController::attributes)
 * onto OUR models:
 *
 *   - "Pages" CMS         → App\Models\LegalPage (global, published).
 *   - "Blog" CMS          → App\Models\BlogPost (global, published) — the SAME
 *                           blog_posts table the website blog renders.
 *   - "FAQ" CMS           → App\Models\PricingFaq (global, active).
 *   - "Banner"            → App\Models\Announcement (active marquee) + active
 *                           packages, mirroring the old banner() shape.
 *   - Notifications       → App\Models\Notification (workspace-scoped; `status`
 *                           boolean = unread, `read_at` timestamp).
 *   - Affiliate code      → users.referral_code (auto-generated on create).
 *   - Credits             → workspace plan limits + user wallet_credits.
 *   - Attributes          → App\Models\ContactCustomField (workspace-scoped).
 *
 * PUBLIC routes: /pages, /blog, /faq, /banner (no auth — marketing content).
 * AUTH routes  : everything else (Sanctum, workspace-scoped).
 */
class ContentController extends Controller
{
    /**
     * GET /pages — published CMS pages (PUBLIC).
     * Contract: old Api\Main\PagesController::index.
     */
    public function pages(Request $request): JsonResponse
    {
        try {
            $pages = LegalPage::query()
                ->where('is_published', true)
                ->orderBy('sort')->orderBy('id')
                ->get()
                ->map(fn (LegalPage $p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'slug' => $p->slug,
                    'subtitle' => $p->subtitle,
                    'desc' => $this->sectionsToHtml($p),
                    'sections' => $p->orderedSections(),
                    'status' => 1,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ])->values();

            return response()->json(['data' => $pages], 200);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /blog — published blog posts (PUBLIC). Reads the SAME blog_posts
     * table the website blog renders (App\Models\BlogPost), so the app and the
     * site always agree. (Previously sourced guidebook_articles — the demo help
     * content — so the app showed stale seeded articles that didn't match the
     * real blog.)
     */
    public function blog(Request $request): JsonResponse
    {
        try {
            $posts = BlogPost::published()->with('category')
                ->orderByDesc('published_at')->orderByDesc('id')
                ->get()
                ->map(fn (BlogPost $p) => $this->blogPostPayload($p))
                ->values();

            return response()->json(['data' => $posts], 200);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /blog/{key} — a single published post by slug OR id (PUBLIC).
     * Same source + shape as the list, so the app's detail screen matches the
     * website. Bumps the view counter exactly like the web detail page.
     */
    public function blogShow(Request $request, string $key): JsonResponse
    {
        try {
            $post = BlogPost::published()->with('category')
                ->where(fn ($q) => $q->where('slug', $key)->orWhere('id', (int) $key))
                ->first();
            if (! $post) {
                return response()->json(['data' => null, 'error' => 'not_found'], 404);
            }
            // Cheap view counter (no model events), mirrors FrontendController::blogShow.
            BlogPost::whereKey($post->id)->update(['views' => (int) $post->views + 1]);

            return response()->json(['data' => $this->blogPostPayload($post)], 200);
        } catch (\Throwable $e) {
            return response()->json(['data' => null, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * One blog post → the app's legacy article shape. `category_id` carries the
     * category NAME (the app renders it as a label, same as before); both image
     * fields carry the featured image URL via the active media disk.
     */
    private function blogPostPayload(BlogPost $p): array
    {
        $img = $p->image_url;   // media_url(featured_image) or null

        return [
            'id'          => $p->id,
            'title'       => $p->title,
            'slug'        => $p->slug,
            'category_id' => $p->category?->name ?? '',
            't_image'     => $img,
            'b_image'     => $img,
            'status'      => 1,
            'sticky'      => 0,
            'approved'    => 1,
            'is_featured' => (int) $p->is_featured,
            'desc'        => $p->body,
            'excerpt'     => $p->excerpt,
            'position'    => 0,
            'created_at'  => $p->published_at ?: $p->created_at,
            'updated_at'  => $p->updated_at,
        ];
    }

    /**
     * GET /faq — active FAQ rows (PUBLIC).
     * Contract: old Api\Main\FAQController::index.
     */
    public function faq(Request $request): JsonResponse
    {
        try {
            $faqs = PricingFaq::query()
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (PricingFaq $f) => [
                    'id' => $f->id,
                    'question' => $f->question,
                    'answer' => $f->answer,
                    'status' => 1,
                    'created_at' => $f->created_at,
                    'updated_at' => $f->updated_at,
                ])->values();

            return response()->json(['data' => $faqs], 200);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /banner — home banners + plans (PUBLIC).
     * Contract: ProfileController::banner. `business` carries the top active
     * announcement (our nearest "slider"); `packages` carries active plans.
     */
    public function banner(Request $request): JsonResponse
    {
        try {
            // The app's banner/slider. We have no slider-image table, so we
            // build a branded promo card: live announcement text when present,
            // otherwise the site name + tagline + brand mark. NEVER null.
            $announcement = Announcement::query()->active()->first();
            $appName = (string) (\App\Models\SystemSetting::get('app_name') ?: config('app.name', 'WaDesk'));
            $tagline = (string) (\App\Models\SystemSetting::get('site.tagline')
                ?: 'AI-Powered WhatsApp CRM, Automation & Bulk Messaging');
            // Brand logos: paper (light) + dark theme, each falling back to the
            // paper logo then the brand mark so they're never empty.
            $logo = \App\Support\Brand::logoUrl('paper') ?: asset('images/brand-mark.png');
            $logoDark = \App\Support\Brand::logoUrl('dark') ?: $logo;
            $business = [
                'id'         => $announcement->id ?? 0,
                'title'      => $appName,
                'text'       => $announcement->text ?? $tagline,
                'image'      => $logo,
                'logo'       => $logo,
                'logo_light' => $logo,
                'logo_dark'  => $logoDark,
                'link_url'   => $announcement->link_url ?? null,
                'link_label' => $announcement->link_label ?? null,
                'tone'       => $announcement->tone ?? 'info',
                'status'     => 1,
            ];

            $packages = Package::query()->where('status', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(function (Package $p) {
                    $hasDiscount = $p->offer_price !== null
                        && (float) $p->offer_price > 0
                        && (float) $p->offer_price < (float) $p->plan_amount;
                    return [
                        'id' => $p->id,
                        'pname' => $p->pname,
                        'detail' => $p->detail,
                        'plan_amount' => (float) $p->plan_amount,
                        'offer_price' => $p->offer_price !== null ? (float) $p->offer_price : null,
                        'currency' => $p->currency,
                        'plan_unit' => $p->plan_unit,
                        'plan_duration' => (int) $p->plan_duration,
                        'free' => (bool) $p->free,
                        'lifetime' => (bool) $p->lifetime,
                        'is_highlighted' => (bool) $p->is_highlighted,
                        'has_discount' => $hasDiscount,
                        // Single "what the customer actually pays" field (offer
                        // price when set, else plan_amount) so the app never has
                        // to re-derive it and never shows/charges the wrong price.
                        'effective_price' => $p->chargeableAmount(),
                        'pfeatures' => $p->features()->pluck('title')->all()
                            ?: array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $p->detail)))),
                    ];
                })->values();

            return response()->json([
                'success' => true,
                'business' => $business,
                'packages' => $packages,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'business' => null,
                'packages' => [],
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /notifications — the user's UNREAD notifications (AUTH).
     * Contract: ProfileController::getNotifications.
     * Workspace-scoped via the model's forCurrentWorkspace scope; `status`
     * boolean true = unread.
     */
    public function notifications(Request $request): JsonResponse
    {
        try {
            $items = Notification::query()
                ->forCurrentWorkspace()
                ->where('status', true) // unread only (matches old is_read=0)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'user_id' => $n->user_id,
                    'title' => $n->notification_title,
                    'message' => $n->notification_msg,
                    'category' => $n->category,
                    'severity' => $n->severity,
                    'icon' => $n->icon,
                    'action_url' => $n->action_url,
                    'is_urgent' => (bool) $n->is_urgent,
                    'is_read' => $n->status ? 0 : 1,
                    'read_at' => optional($n->read_at)->toDateTimeString(),
                    'created_at' => $n->created_at,
                ])->values();

            return response()->json($items, 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to load notifications', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /notifications/mark-as-read/{id} — mark one read (AUTH).
     * Contract: ProfileController::markNotificationsAsRead.
     */
    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        try {
            $updated = Notification::query()
                ->forCurrentWorkspace()
                ->where('id', $id)
                ->update(['status' => false, 'read_at' => now()]);

            if (! $updated) {
                return response()->json(['error' => 'Notification not found'], 404);
            }

            return response()->json(['success' => true], 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to update notification', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /notifications/mark-all-read — mark all read (AUTH).
     * Contract: ProfileController::markAllNotificationsAsRead.
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        try {
            Notification::query()
                ->forCurrentWorkspace()
                ->where('status', true)
                ->update(['status' => false, 'read_at' => now()]);

            return response()->json(['success' => true], 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to update notifications', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /affiliate/code — the user's referral code + link (AUTH).
     * Contract: AffiliateController::generateLink. Our users always have a
     * referral_code (auto-generated on create); we never regenerate.
     */
    public function affiliateCode(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (empty($user->referral_code)) {
                $user->referral_code = User::generateUniqueReferralCode();
                $user->save();
            }
            $code = $user->referral_code;

            return response()->json([
                'success' => true,
                'message' => 'Referral link generated successfully',
                'refer_code' => $code,
                'referral_link' => url('/register?ref=' . $code),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate referral link',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /credits — the user's plan limits + wallet credits (AUTH).
     * Contract: WhatsAppMessageApiController::getUserCredits. Limits come from
     * the workspace's effective plan; wallet from users.wallet_credits.
     */
    public function credits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $workspace = $user->currentWorkspace;
            $isAdmin = strtolower((string) $user->role) === 'admin';

            // Normalise limits so "unlimited" is ALWAYS -1 (some packages store
            // 0 for unlimited, others -1). The app can treat -1 as ∞ uniformly.
            $limit = function (string $key) use ($workspace) {
                if (! $workspace) { return 0; }
                $v = (int) $workspace->effectiveLimit($key, 0);
                return $v <= 0 ? -1 : $v;
            };

            $package = $workspace?->billingPackage();
            $planName = $package?->pname;
            $planExpiry = $workspace && $workspace->plan_ends_at
                ? $workspace->plan_ends_at->toDateTimeString()
                : null;

            // Plan (overflow) usage — the app shows "X messages" available. With
            // overflow billing the plan grants `monthly_messages_limit` free
            // messages per CALENDAR MONTH, then the wallet covers overflow. So
            // "available" = free remaining this month (+ wallet buffer). REPORTING
            // ONLY (no charge/gate) — mirrors OverflowBilling's meter: outbound
            // rows across inbox_messages + messages this month.
            $monthlyLimit   = $limit('monthly_messages_limit');   // 0 / negative = unlimited
            $usedThisMonth  = 0;
            $deliveredCount = 0;
            try {
                if ($workspace) {
                    $monthStart = now()->startOfMonth();
                    $usedThisMonth = \App\Models\InboxMessage::query()
                            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspace->id))
                            ->where('direction', 'out')
                            ->where('created_at', '>=', $monthStart)
                            ->count()
                        + \App\Models\Message::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('direction', 'out')
                            ->where('created_at', '>=', $monthStart)
                            ->count();
                    // All-time DELIVERED (outbound). Outbound history is unified
                    // in `inbox_messages` — every /chat, campaign, broadcast and
                    // team-inbox send is mirrored there. The `messages` table on
                    // its own holds only a handful of rows and is EMPTY for most
                    // workspaces, so a Message-only count read 0 for nearly
                    // everyone (the reported "always 0"). Count the inbox store
                    // (scoped via conversation.workspace_id — inbox_messages has
                    // no workspace_id column) plus any conversation-less `messages`
                    // rows, which never get mirrored, so there is no double count.
                    $deliveredCount = \App\Models\InboxMessage::query()
                            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspace->id))
                            ->where('direction', 'out')
                            ->whereIn('status', ['sent', 'delivered', 'read'])
                            ->count()
                        + \App\Models\Message::query()
                            ->where('workspace_id', $workspace->id)
                            ->whereNull('conversation_id')
                            ->where('direction', 'out')
                            ->whereIn('status', ['sent', 'delivered', 'read'])
                            ->count();
                }
            } catch (\Throwable $e) { $usedThisMonth = 0; $deliveredCount = 0; }

            $cpm           = max(1, (int) \App\Models\SystemSetting::get('credits_per_message', 1));
            $walletCredits = (int) ($user->wallet_credits ?? 0);
            $walletMsgs    = (int) floor($walletCredits / $cpm);
            $planRemaining = $monthlyLimit > 0 ? max(0, $monthlyLimit - $usedThisMonth) : -1; // -1 = unlimited

            // ── Billing mode ────────────────────────────────────────────────
            // BSP / reseller (or platform pay-per-message): every message is
            // billed from the money wallet — there is NO monthly plan allowance.
            // Otherwise the plan's monthly_messages_limit governs, with the
            // wallet only covering overflow.
            $isBsp = $workspace
                ? \App\Services\MessageBillingService::payPerMessageFor($workspace)
                : false;
            $billingMode = $isBsp ? 'wallet' : 'plan';

            // ── Money (dynamic currency — the user's workspace currency) ─────
            // 1 credit == 1 money-minor in the money-wallet model, so the wallet
            // money value = wallet_currency_minor / 100.
            $cur         = \App\Support\FormatSettings::currencyFor($workspace);
            $curCode     = $cur?->code ?: ($user->wallet_currency_code ?: 'USD');
            $curSym      = $cur?->symbol ?: '';
            $minorPerCr  = \App\Services\MessageCreditRate::minorPerCredit();
            // Money value of the SPENDABLE balance = wallet_credits × rate. Use
            // wallet_credits (the send-path balance) not wallet_currency_minor —
            // the latter only tracks paid top-ups and drifts when credits are
            // granted (affiliate / admin) without a currency row.
            $walletMinor = (int) round($walletCredits * $minorPerCr);
            $walletMoney = round($walletMinor / 100, 2);
            // Base (flat) price of one message, in money. Real per-country rates
            // may differ; this is the default rate the app shows as "per message".
            $perMsgMinor = (int) round($cpm * $minorPerCr);
            $perMsgMoney = round($perMsgMinor / 100, 2);

            // Money actually spent on messages this calendar month (charge ledger).
            $spentMinor = 0;
            try {
                if ($workspace) {
                    $spentMinor = (int) \App\Models\MessageCharge::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('status', 'charged')
                        ->where('created_at', '>=', now()->startOfMonth())
                        ->sum('revenue_minor');
                }
            } catch (\Throwable $e) { $spentMinor = 0; }
            $spentMoney = round($spentMinor / 100, 2);

            // "messages_remaining" the app should show, per billing mode:
            //   -1              → unlimited (admin, or uncapped plan in plan mode)
            //   wallet (BSP)    → what the wallet money can still buy
            //   plan (non-BSP)  → the plan's MONTHLY message limit left this month
            //                     (wallet is exposed separately as overflow buffer)
            if ($isAdmin) {
                $messagesRemaining = -1;
            } elseif ($isBsp) {
                $messagesRemaining = $walletMsgs;
            } else {
                $messagesRemaining = $planRemaining; // plan monthly remaining; -1 = unlimited
            }
            // For a non-BSP plan user the wallet only covers OVERFLOW once the
            // monthly allowance is spent — surfaced separately so the app can
            // show "plan limit" as the primary number.
            $walletBufferMsgs = $isBsp ? 0 : $walletMsgs;

            return response()->json([
                'success' => true,
                'message' => 'User credits fetched successfully',
                'data' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_admin' => $isAdmin,
                    'package_id' => $package?->id,
                    'plan_name' => $planName,
                    'plan_expiry' => $planExpiry,

                    // ── Which model governs this user ──
                    // 'plan'   = monthly message allowance (non-BSP) — the app
                    //            should show monthly_messages_limit + plan_messages_remaining.
                    // 'wallet' = pay-per-message from the money wallet (BSP/reseller)
                    //            — the app should show wallet_balance + amount_per_message.
                    'billing_mode' => $billingMode,
                    'is_bsp' => $isBsp,

                    'monthly_messages_limit' => $limit('monthly_messages_limit'), // -1 = unlimited
                    'contacts_limit' => $limit('contacts_limit'),
                    'device_limit' => $limit('device_limit'),
                    'template_limit' => $limit('template_limit'),
                    'broadcast_limit' => $limit('broadcast_limit'),
                    'groups_limit' => $limit('groups_limit'),

                    // ── Plan allowance (relevant when billing_mode = 'plan') ──
                    'plan_messages_used' => $usedThisMonth,          // sent this month
                    'plan_messages_remaining' => $planRemaining,     // this month; -1 = unlimited

                    // ── Money wallet (relevant when billing_mode = 'wallet') ──
                    'currency_code' => $curCode,
                    'currency_symbol' => $curSym,
                    // wallet_amount kept for backward-compat = raw credit count.
                    'wallet_amount' => $walletCredits,
                    'wallet_amount_minor' => $walletMinor,           // money in minor units
                    'wallet_balance' => $walletMoney,                // money (major units)
                    'wallet_balance_formatted' => $curSym . number_format($walletMoney, 2),

                    // ── Per-message deduction ──
                    // credit_per_message = credits burned per send (legacy unit).
                    'credit_per_message' => $cpm,
                    // amount_per_message = MONEY deducted per message (base rate).
                    'amount_per_message' => $perMsgMoney,
                    'amount_per_message_minor' => $perMsgMinor,
                    'amount_per_message_formatted' => $curSym . number_format($perMsgMoney, 2),

                    // ── Spend this month (money) ──
                    'spent_this_month' => $spentMoney,
                    'spent_this_month_formatted' => $curSym . number_format($spentMoney, 2),

                    // Messages the user can still send under their billing mode.
                    // -1 = unlimited. For non-BSP this is the PLAN monthly limit
                    // remaining; wallet_buffer_messages is the extra the wallet
                    // can cover once the plan allowance runs out.
                    'messages_remaining' => $messagesRemaining,
                    'wallet_buffer_messages' => $walletBufferMsgs,

                    'autoreply' => $package && $package->autoreply ? 1 : 0,
                    'bulkmessage' => $package && $package->bulkmessage ? 1 : 0,
                    'schedulemessage' => $package && $package->schedulemessage ? 1 : 0,
                    'delivered_count' => $deliveredCount,
                    'unlimited_access' => $isAdmin,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user credits',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /attributes — fixed + custom contact merge fields (AUTH).
     * Contract: BlogController::attributes (old). Custom fields are
     * workspace-scoped (App\Models\ContactCustomField).
     */
    public function attributes(Request $request): JsonResponse
    {
        try {
            $user      = $request->user();
            $workspace = $user->currentWorkspace;

            // 1. FIXED attributes — the columns every Contact has natively.
            //    Previously only 4 were exposed (First Name, Last Name, Phone,
            //    Email) which left the app unable to pick {{name}}, {{address}},
            //    {{company}}, {{language}}, {{country_code}} etc. Expanded to
            //    every commonly-used Contact column.
            $fixed = [
                ['id' => 'fixed_1',  'name' => 'Full Name',     'key' => 'name',         'type' => 'fixed'],
                ['id' => 'fixed_2',  'name' => 'First Name',    'key' => 'first_name',   'type' => 'fixed'],
                ['id' => 'fixed_3',  'name' => 'Last Name',     'key' => 'last_name',    'type' => 'fixed'],
                ['id' => 'fixed_4',  'name' => 'Phone Number',  'key' => 'phone_number', 'type' => 'fixed'],
                ['id' => 'fixed_5',  'name' => 'Email',         'key' => 'email',        'type' => 'fixed'],
                ['id' => 'fixed_6',  'name' => 'Country Code',  'key' => 'country_code', 'type' => 'fixed'],
                ['id' => 'fixed_7',  'name' => 'Address',       'key' => 'address',      'type' => 'fixed'],
                ['id' => 'fixed_8',  'name' => 'Language',      'key' => 'language',     'type' => 'fixed'],
                ['id' => 'fixed_9',  'name' => 'Title',         'key' => 'title',        'type' => 'fixed'],
                ['id' => 'fixed_10', 'name' => 'Subject',       'key' => 'subject',      'type' => 'fixed'],
            ];

            // 2. WORKSPACE-DEFINED custom fields (the schema rows). Empty for
            //    workspaces that haven't created any custom fields yet.
            $custom = [];
            $customKnownKeys = [];
            if ($workspace) {
                $custom = ContactCustomField::query()
                    ->forWorkspace($workspace->id)
                    ->orderBy('sort')->orderBy('label')
                    ->get()
                    ->map(function (ContactCustomField $a) use (&$customKnownKeys) {
                        $customKnownKeys[] = (string) $a->key;
                        return [
                            'id'    => 'custom_' . $a->id,
                            'name'  => (string) $a->label,
                            'key'   => (string) $a->key,
                            'value' => null,
                            'type'  => 'custom',
                        ];
                    })->all();
            }

            // 3. DISCOVERED keys — union of every key that ACTUALLY appears in
            //    this workspace's contacts' `custom_attributes` JSON column.
            //    This surfaces ad-hoc attributes the operator may have written
            //    directly without first defining a ContactCustomField schema
            //    row — without this step the app couldn't reference {{my_key}}
            //    in a template even though some contacts have my_key set.
            $discovered = [];
            if ($workspace) {
                $seen = array_flip($customKnownKeys);
                \App\Models\Contact::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereNotNull('custom_attributes')
                    ->limit(5000)
                    ->cursor()
                    ->each(function ($c) use (&$discovered, &$seen) {
                        $attrs = is_array($c->custom_attributes) ? $c->custom_attributes : [];
                        foreach ($attrs as $k => $_v) {
                            $k = (string) $k;
                            if ($k === '' || isset($seen[$k])) continue;
                            $seen[$k] = true;
                            $discovered[] = [
                                'id'    => 'custom_' . $k,
                                'name'  => ucwords(str_replace(['_', '-'], ' ', $k)),
                                'key'   => $k,
                                'value' => null,
                                // Fold ad-hoc keys found in contact data INTO custom —
                                // the app only ever sees two groups: fixed + custom.
                                'type'  => 'custom',
                            ];
                        }
                    });
            }

            $all = array_merge($fixed, $custom, $discovered);

            // Wrapped + raw — wrapped is the consistent {success,data,total}
            // shape used by every other endpoint; the raw array is preserved
            // at the top level under `attributes` AND as a sibling list so
            // callers that pre-date the wrapping (per the older api.md spec)
            // still work without a code change.
            return response()->json([
                'success'    => true,
                'data'       => $all,
                'attributes' => $all,
                'total'      => count($all),
                'counts'     => [
                    'fixed'  => count($fixed),
                    // Ad-hoc keys discovered in contact data are folded into custom,
                    // so the app sees exactly two groups: fixed + custom.
                    'custom' => count($custom) + count($discovered),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::warning('[App\Content] attributes load failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load attributes',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Render a LegalPage's sections into a single HTML blob for `desc`. */
    private function sectionsToHtml(LegalPage $page): string
    {
        $html = '';
        foreach ($page->orderedSections() as $s) {
            $title = trim((string) ($s['title'] ?? ''));
            $body = (string) ($s['body'] ?? '');
            if ($title !== '') {
                $html .= '<h3>' . e($title) . '</h3>';
            }
            $html .= '<div>' . $body . '</div>';
        }
        return $html;
    }
}
