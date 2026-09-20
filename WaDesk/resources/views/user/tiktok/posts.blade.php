@php
    use Illuminate\Support\Str;
    $tiktokGlyph = '<path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/>';
    $statusPill = fn (string $s) => match ($s) {
        'published'  => ['cls' => 'bg-wa-mint text-wa-deep', 'label' => __('Published')],
        'processing' => ['cls' => 'bg-accent-amber/15 text-[#7B5A14]', 'label' => __('In TikTok inbox')],
        'failed'     => ['cls' => 'bg-accent-coral/10 text-accent-coral', 'label' => __('Failed')],
        default      => ['cls' => 'bg-paper-100 text-ink-600', 'label' => Str::title($s)],
    };
@endphp

<x-layouts.user :title="__('TikTok posts')" nav-key="tiktok-posts" page="user-tiktok-posts">

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('TikTok') }} · {{ __('Posts') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">
                    {{ __('TikTok') }} <span class="italic text-wa-deep">{{ __('posts') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Videos sent to TikTok from :brand. They land in the creator\'s TikTok inbox to review and post from the app.', ['brand' => brand_name()]) }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
                <a href="{{ route('user.tiktok.insights') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('My videos') }}</a>
                <a href="{{ route('user.tiktok.posts.create') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-ink-900 text-paper-0 text-[13px] font-semibold hover:bg-ink-800 transition">
                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M8 3.5v9M3.5 8h9"/></svg>{{ __('Create post') }}
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($accounts->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 bg-ink-900"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="#fff">{!! $tiktokGlyph !!}</svg></span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a TikTok account first.') }}</p>
                <a href="{{ route('user.tiktok.accounts') }}" class="mt-3 inline-flex px-4 py-2 rounded-full bg-ink-900 text-paper-0 text-[12.5px] font-semibold hover:bg-ink-800">{{ __('Go to accounts') }}</a>
            </div>
        @else
            {{-- Account filter --}}
            @if ($accounts->count() > 1)
                <form method="GET" class="flex items-center gap-2">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-400">{{ __('Account') }}</span>
                    <select name="account" onchange="this.form.submit()" class="rounded-full border border-paper-200 bg-paper-0 px-4 py-2 text-[12px] focus:outline-none focus:border-wa-deep">
                        <option value="">{{ __('All accounts') }}</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a->id }}" @selected($accountId === $a->id)>{{ $a->display_name ?: ('@' . ltrim((string) $a->username, '@')) }}</option>
                        @endforeach
                    </select>
                </form>
            @endif

            <section class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-[12.5px]">
                        <thead>
                            <tr class="text-left text-ink-500 font-mono text-[10px] uppercase tracking-[0.14em] border-b border-paper-200">
                                <th class="px-5 py-3 font-medium">{{ __('Caption') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('Account') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('When') }}</th>
                                <th class="px-4 py-3 font-medium text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-100">
                            @forelse ($posts as $p)
                                @php $pill = $statusPill($p->status); @endphp
                                <tr class="hover:bg-paper-50/60">
                                    <td class="px-5 py-3 max-w-[360px]">
                                        <div class="text-ink-800 line-clamp-1">{{ $p->caption ?: __('(no caption)') }}</div>
                                        <a href="{{ data_get($p->media_json, 'video_url') }}" target="_blank" rel="noopener" class="text-[10.5px] text-ink-400 font-mono line-clamp-1 hover:text-wa-deep">{{ data_get($p->media_json, 'video_url') }}</a>
                                    </td>
                                    <td class="px-4 py-3 text-ink-600">{{ $p->account?->display_name ?: ('@' . ltrim((string) $p->account?->username, '@')) }}</td>
                                    <td class="px-4 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-mono {{ $pill['cls'] }}">{{ $pill['label'] }}</span>
                                        @if ($p->error)<div class="text-[10px] text-accent-coral mt-1 max-w-[200px] truncate" title="{{ $p->error }}">{{ $p->error }}</div>@endif
                                    </td>
                                    <td class="px-4 py-3 text-ink-500 font-mono text-[11px]">{{ $p->created_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-0.5 justify-end">
                                            @if ($p->publish_id && $p->status === 'processing')
                                                <form method="POST" action="{{ route('user.tiktok.posts.status', $p->id) }}">@csrf
                                                    <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500" title="{{ __('Check status') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3"/><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8"/></svg></button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('user.tiktok.posts.destroy', $p->id) }}" data-confirm="{{ __('Remove this post from the list?') }}">@csrf @method('DELETE')
                                                <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10" title="{{ __('Remove') }}"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-12 text-center text-ink-500">{{ __('No posts yet.') }} <a href="{{ route('user.tiktok.posts.create') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Create your first post') }}</a></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($posts->hasPages())
                    <div class="px-5 py-3 border-t border-paper-200">{{ $posts->links() }}</div>
                @endif
            </section>
        @endif
    </main>
</x-layouts.user>
