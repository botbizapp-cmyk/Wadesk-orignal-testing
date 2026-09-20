@php
    /** @var \Illuminate\Support\Collection $shops */
    /** @var bool $configured */
    /** @var array $tabs */
    /** @var string $activeTab */
    $glyph = '<path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/>';
    $shop = $shop ?? $shops->first();
    $stats    = (array) data_get($shop?->meta_json, 'stats', []);
    $orders   = (array) data_get($shop?->meta_json, 'recent_orders', []);
    $msgs     = (array) data_get($shop?->meta_json, 'recent_messages', []);
    $products = (array) data_get($shop?->meta_json, 'products', []);
    $orderPill = fn ($st) => match ($st) {
        'To ship'   => 'bg-accent-amber/15 text-[#7B5A14]',
        'Shipped'   => 'bg-wa-deep/10 text-wa-deep',
        'Delivered' => 'bg-wa-mint text-wa-deep',
        default     => 'bg-paper-100 text-ink-600',
    };
    $live = $shop && $shop->isLive() && $shop->shop_cipher;
@endphp

<x-layouts.user :title="__('TikTok Shop')" nav-key="integrations" page="user-tiktok-shop">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Integration') }} · {{ __('E-commerce') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('TikTok') }} <span class="italic text-wa-deep">{{ __('Shop') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Manage your TikTok Shop from :brand — orders, products and buyer messages in one place, alongside Shopify and WooCommerce.', ['brand' => brand_name()]) }}</p>
            </div>
            <a href="{{ url('/integrations') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium shrink-0">{{ __('All integrations') }}</a>
        </div>

        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if (! $configured)
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $glyph !!}</svg></span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('TikTok Shop is not enabled yet') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 max-w-md mx-auto">{{ __('The platform admin needs to add the TikTok Shop Partner app credentials under Settings → :brand Message.', ['brand' => brand_name()]) }}</p>
            </div>
        @elseif (! $shop)
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $glyph !!}</svg></span>
                <div class="text-sm text-ink-800 font-semibold">{{ __('No TikTok Shop connected') }}</div>
                <p class="text-[12.5px] text-ink-500 mt-1 mb-4 max-w-md mx-auto">{{ __('Authorize your TikTok Shop seller account to sync orders, products and buyer conversations.') }}</p>
                <a href="{{ route('user.tiktok.shop.connect') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-ink-900 text-paper-0 text-[12.5px] font-semibold hover:bg-ink-800">{{ __('Connect TikTok Shop') }}</a>
            </div>
        @else
            {{-- ===== Connected: store dashboard (sidebar + tabs) ===== --}}
            {{-- Shop identity + horizontal tab bar (full width — no side column) --}}
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card">
                <div class="px-5 py-4 flex items-center justify-between gap-4 border-b border-paper-200">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <span class="w-11 h-11 rounded-xl shrink-0 grid place-items-center bg-ink-900"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $glyph !!}</svg></span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-[16px] font-semibold text-ink-900 truncate">{{ $shop->shop_name ?: $shop->shop_id }}</span>
                                @if ($live)
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-mono bg-wa-mint text-wa-deep border border-wa-green/40 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-mono bg-accent-amber/15 text-[#7B5A14] border border-accent-amber/40 shrink-0"><span class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('Pending') }}</span>
                                @endif
                            </div>
                            <div class="font-mono text-[10.5px] text-ink-500 mt-0.5 truncate">{{ $shop->region ?: __('region pending') }} · TikTok Shop{{ $shop->seller_name ? ' · ' . $shop->seller_name : '' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        {{-- Multi-shop switcher — a seller can connect several shops. --}}
                        @if ($shops->count() > 1)
                            <form method="GET" class="shrink-0">
                                <input type="hidden" name="tab" value="{{ $activeTab }}">
                                <div class="relative">
                                    <select name="shop" onchange="this.form.submit()"
                                        class="appearance-none rounded-full border border-paper-200 bg-paper-0 pl-3.5 pr-8 py-2 text-[12px] font-semibold focus:outline-none focus:border-wa-deep">
                                        @foreach ($shops as $so)
                                            <option value="{{ $so->id }}" @selected($shop && $shop->id === $so->id)>{{ $so->shop_name ?: $so->shop_id }}</option>
                                        @endforeach
                                    </select>
                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-ink-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6l4 4 4-4"/></svg>
                                </div>
                            </form>
                        @endif
                        <a href="{{ route('user.tiktok.shop.connect') }}" class="px-3.5 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium shrink-0" title="{{ __('Connect another shop') }}">+ {{ __('Shop') }}</a>
                        <a href="{{ url('/team-inbox') }}" class="px-3.5 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium shrink-0">{{ __('Buyer inbox') }}</a>
                    </div>
                </div>
                {{-- Tabs --}}
                <div class="px-3 py-2 flex items-center gap-1 overflow-x-auto">
                    @foreach ($tabs as $tab => $label)
                        @php
                            $isActive = $activeTab === $tab;
                            $badge = match ($tab) {
                                'orders'   => (string) ($stats['orders'] ?? count($orders)),
                                'products' => (string) ($stats['products'] ?? count($products)),
                                'messages' => (string) ($stats['unread_messages'] ?? count($msgs)),
                                default    => null,
                            };
                        @endphp
                        <a href="?tab={{ $tab }}&shop={{ $shop->id }}"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-[12.5px] whitespace-nowrap transition {{ $isActive ? 'bg-wa-deep text-paper-0 font-semibold' : 'text-ink-600 hover:bg-paper-100' }}">
                            {{ __($label) }}
                            @if ($badge !== null)
                                <span class="font-mono text-[10px] px-1.5 py-0.5 rounded-full {{ $isActive ? 'bg-paper-0/25 text-paper-0' : 'bg-paper-100 text-ink-500' }}">{{ $badge }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Tab content (full width) --}}
            <div class="space-y-5">
                    @if ($activeTab === 'overview')
                        {{-- KPIs --}}
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            @foreach ([['orders','orders'],['revenue','revenue'],['products','products'],['unread_messages','unread chats']] as [$k,$lbl])
                                <div class="bg-paper-0 border border-paper-200 rounded-[14px] p-4 shadow-card">
                                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __($lbl) }}</div>
                                    <div class="mt-2 font-serif text-[28px] leading-none">{{ $stats[$k] ?? '—' }}</div>
                                </div>
                            @endforeach
                        </div>
                        {{-- Recent orders + messages --}}
                        <div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-5 items-start">
                            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                                <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Recent orders') }}</span>
                                    <a href="?tab=orders&shop={{ $shop->id }}" class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('All orders') }}</a>
                                </div>
                                <div class="divide-y divide-paper-100">
                                    @foreach (array_slice($orders, 0, 4) as $o)
                                        <div class="px-5 py-3 flex items-center gap-3">
                                            <div class="min-w-0 flex-1">
                                                <div class="text-[12.5px] text-ink-800 truncate">{{ $o['item'] ?? '' }}@if(($o['qty'] ?? 1) > 1) <span class="text-ink-400">×{{ $o['qty'] }}</span>@endif</div>
                                                <div class="text-[10.5px] text-ink-500 font-mono">#{{ $o['id'] ?? '' }} · {{ $o['buyer'] ?? '' }} · {{ $o['when'] ?? '' }}</div>
                                            </div>
                                            <div class="text-[12px] font-semibold text-ink-800 shrink-0">{{ $o['amount'] ?? '' }}</div>
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-mono shrink-0 {{ $orderPill($o['status'] ?? '') }}">{{ $o['status'] ?? '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                                <div class="px-5 py-3 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Buyer messages') }}</div>
                                <div class="divide-y divide-paper-100">
                                    @foreach ($msgs as $m)
                                        <div class="px-5 py-3 flex items-start gap-2.5">
                                            <span class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0 {{ ($m['unread'] ?? false) ? 'bg-wa-green' : 'bg-paper-300' }}"></span>
                                            <div class="min-w-0">
                                                <div class="text-[12px] text-ink-800 line-clamp-2">{{ $m['text'] ?? '' }}</div>
                                                <div class="text-[10.5px] text-ink-500 font-mono mt-0.5">{{ $m['buyer'] ?? '' }} · {{ $m['when'] ?? '' }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="px-5 py-2.5 border-t border-paper-100"><a href="{{ url('/team-inbox') }}" class="text-[12px] text-wa-deep font-semibold hover:underline">{{ __('Open buyer inbox') }} →</a></div>
                            </section>
                        </div>

                    @elseif ($activeTab === 'orders')
                        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Orders') }} · {{ $stats['orders'] ?? count($orders) }}</div>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[640px] text-[12.5px]">
                                    <thead><tr class="text-left text-ink-500 font-mono text-[10px] uppercase tracking-[0.14em] border-b border-paper-200">
                                        <th class="px-5 py-3 font-medium">{{ __('Order') }}</th><th class="px-4 py-3 font-medium">{{ __('Buyer') }}</th><th class="px-4 py-3 font-medium">{{ __('Total') }}</th><th class="px-4 py-3 font-medium">{{ __('Status') }}</th><th class="px-4 py-3 font-medium">{{ __('When') }}</th>
                                    </tr></thead>
                                    <tbody class="divide-y divide-paper-100">
                                        @forelse ($orders as $o)
                                            <tr class="hover:bg-paper-50/60">
                                                <td class="px-5 py-3"><div class="text-ink-800">{{ $o['item'] ?? '' }}@if(($o['qty'] ?? 1) > 1) <span class="text-ink-400">×{{ $o['qty'] }}</span>@endif</div><div class="text-[10.5px] text-ink-400 font-mono">#{{ $o['id'] ?? '' }}</div></td>
                                                <td class="px-4 py-3 text-ink-600">{{ $o['buyer'] ?? '' }}</td>
                                                <td class="px-4 py-3 font-semibold text-ink-800">{{ $o['amount'] ?? '' }}</td>
                                                <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-mono {{ $orderPill($o['status'] ?? '') }}">{{ $o['status'] ?? '' }}</span></td>
                                                <td class="px-4 py-3 text-ink-500 font-mono text-[11px]">{{ $o['when'] ?? '' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="px-5 py-10 text-center text-ink-500">{{ __('Orders sync here once the order scope is approved.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </section>

                    @elseif ($activeTab === 'products')
                        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Products') }} · {{ $stats['products'] ?? count($products) }}</div>
                            @if ($products)
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 p-4">
                                    @foreach ($products as $p)
                                        <div class="border border-paper-200 rounded-xl overflow-hidden bg-paper-0">
                                            <div class="aspect-square bg-paper-100"><img src="{{ $p['img'] ?? '' }}" alt="" referrerpolicy="no-referrer" loading="lazy" class="w-full h-full object-cover"></div>
                                            <div class="p-3">
                                                <div class="text-[12.5px] font-semibold text-ink-800 line-clamp-1">{{ $p['name'] ?? '' }}</div>
                                                <div class="text-[13px] font-serif text-ink-900 mt-0.5">{{ $p['price'] ?? '' }}</div>
                                                <div class="flex items-center justify-between mt-2 text-[10.5px] font-mono text-ink-500">
                                                    <span>{{ __(':n sold', ['n' => $p['sold'] ?? 0]) }}</span>
                                                    @if (($p['status'] ?? '') === 'Out of stock')
                                                        <span class="px-1.5 py-0.5 rounded bg-accent-coral/10 text-accent-coral">{{ __('Out of stock') }}</span>
                                                    @else
                                                        <span>{{ __(':n in stock', ['n' => $p['stock'] ?? 0]) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="px-5 py-10 text-center text-[12.5px] text-ink-500">{{ __('Products sync here once the product scope is approved.') }}</div>
                            @endif
                        </section>

                    @elseif ($activeTab === 'messages')
                        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-paper-200 flex items-center justify-between">
                                <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Buyer messages') }}</span>
                                <a href="{{ url('/team-inbox') }}" class="text-[11.5px] text-wa-deep font-semibold hover:underline">{{ __('Open in inbox') }} →</a>
                            </div>
                            <div class="divide-y divide-paper-100">
                                @forelse ($msgs as $m)
                                    <div class="px-5 py-3.5 flex items-start gap-3">
                                        <span class="w-8 h-8 rounded-full bg-paper-100 grid place-items-center text-ink-500 text-[12px] font-semibold shrink-0">{{ strtoupper(mb_substr($m['buyer'] ?? 'B', 0, 1)) }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2"><span class="text-[12.5px] font-semibold text-ink-800">{{ $m['buyer'] ?? '' }}</span>@if($m['unread'] ?? false)<span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>@endif</div>
                                            <div class="text-[12px] text-ink-600 mt-0.5">{{ $m['text'] ?? '' }}</div>
                                        </div>
                                        <span class="text-[10.5px] text-ink-400 font-mono shrink-0">{{ $m['when'] ?? '' }}</span>
                                    </div>
                                @empty
                                    <div class="px-5 py-10 text-center text-ink-500">{{ __('Buyer chats flow into the unified inbox once the customer-service scope is approved.') }}</div>
                                @endforelse
                            </div>
                        </section>

                    @else {{-- settings --}}
                        <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                            <div class="px-5 py-3.5 border-b border-paper-200 font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Settings') }}</div>
                            <div class="p-5 space-y-3 text-[12.5px]">
                                <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('Shop name') }}</span><span class="font-semibold text-ink-800">{{ $shop->shop_name }}</span></div>
                                <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('Shop ID') }}</span><span class="font-mono text-ink-700">{{ $shop->shop_id }}</span></div>
                                <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('Region') }}</span><span class="font-mono text-ink-700">{{ $shop->region ?: '—' }}</span></div>
                                <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('Shop cipher') }}</span><span class="font-mono text-ink-400 text-[11px] truncate max-w-[220px]">{{ $shop->shop_cipher ? '••••' . mb_substr($shop->shop_cipher, -6) : __('pending') }}</span></div>
                                <div class="pt-3 border-t border-paper-100 flex items-center justify-between">
                                    <span class="text-ink-500">{{ __('Disconnect this shop') }}</span>
                                    <form method="POST" action="{{ route('user.tiktok.shop.disconnect', $shop->id) }}" data-confirm="{{ __('Disconnect this TikTok Shop?') }}">@csrf @method('DELETE')
                                        <button type="submit" class="px-3.5 py-1.5 rounded-full border border-accent-coral/40 text-accent-coral hover:bg-accent-coral/10 text-[12px] font-semibold">{{ __('Disconnect') }}</button>
                                    </form>
                                </div>
                            </div>
                        </section>
                    @endif

                    {{-- Requirements note (shown on overview + settings) --}}
                    @if (in_array($activeTab, ['overview', 'settings'], true))
                        <section class="rounded-2xl border border-dashed border-paper-300 bg-paper-50/60 p-5 text-[12px] text-ink-600">
                            <div class="font-semibold text-ink-800 mb-1.5">{{ __('Live data & buyer messaging') }}</div>
                            {{ __('Orders, products and buyer chats shown here go live once your TikTok Shop Partner app is approved for the') }}
                            <span class="font-mono">seller.customer_service</span>, <span class="font-mono">order</span> {{ __('and') }} <span class="font-mono">product</span> {{ __('scopes at') }}
                            <a href="https://partner.tiktokshop.com" target="_blank" rel="noopener" class="text-wa-deep font-semibold hover:underline">partner.tiktokshop.com</a>.
                        </section>
                    @endif
            </div>
        @endif
    </main>
</x-layouts.user>
