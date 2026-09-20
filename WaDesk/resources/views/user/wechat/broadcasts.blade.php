@php
    /** @var \Illuminate\Support\Collection $channels */
    /** @var \Illuminate\Support\Collection $broadcasts */
    $pill = fn ($s) => match ($s) {
        'done'   => ['bg-wa-mint text-wa-deep', __('Sent')],
        'failed' => ['bg-accent-coral/10 text-accent-coral', __('Failed')],
        default  => ['bg-paper-100 text-ink-600', __('Draft')],
    };
@endphp

<x-layouts.user :title="__('WeChat broadcasts')" nav-key="more" page="user-wechat-broadcasts">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('WeChat') }} · {{ __('Broadcasts') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('WeChat') }} <span class="italic" style="color:#07C160">{{ __('broadcasts') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Mass-send a message to all followers, a tag, or picked users. Note: WeChat limits Service Accounts to about 4 mass sends per month.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.wechat.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Channels') }}</a>
                @unless ($channels->isEmpty())
                    <a href="{{ route('user.wechat.broadcasts.create') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#07C160">
                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v10M3 8h10" /></svg>
                        {{ __('New broadcast') }}
                    </a>
                @endunless
            </div>
        </div>

        @if (session('success'))
            <div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>
        @endif
        @if (session('error') || $errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>
        @endif

        @if ($channels->isEmpty())
            <div class="bg-paper-0 border border-paper-200 rounded-2xl p-10 text-center shadow-card">
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#07C160">W</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a WeChat channel first.') }}</p>
                <a href="{{ route('user.wechat.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#07C160">{{ __('Connect a channel') }}</a>
            </div>
        @else
            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead>
                            <tr class="text-left text-ink-500 border-b border-paper-200 bg-paper-50/50">
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Broadcast') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Audience') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Status') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($broadcasts as $b)
                                @php [$cls, $lbl] = $pill($b->status); @endphp
                                <tr class="border-b border-paper-100 last:border-0 hover:bg-paper-50/60">
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-ink-900">{{ $b->name }}</div>
                                        <div class="text-[10.5px] text-ink-500 font-mono mt-0.5">{{ $b->created_at?->format('d M Y · H:i') }}@if($b->last_error) · <span class="text-accent-coral">{{ $b->last_error }}</span>@endif</div>
                                    </td>
                                    <td class="px-5 py-3 text-[11.5px] text-ink-600">{{ ['all'=>__('All followers'),'tag'=>__('Tag').' '.$b->tag_id,'list'=>$b->total.' '.__('users')][$b->audience] ?? $b->audience }}</td>
                                    <td class="px-5 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-mono {{ $cls }}">{{ $lbl }}</span></td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @if ($b->status !== 'done')
                                                <form method="POST" action="{{ route('user.wechat.broadcasts.send', $b->id) }}" onsubmit="return confirm('{{ __('Send this broadcast now? WeChat counts it against your ~4/month limit.') }}')">@csrf
                                                    <button class="px-3 py-1 rounded-full text-white text-[11px] font-semibold" style="background:#07C160">{{ __('Send') }}</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('user.wechat.broadcasts.destroy', $b->id) }}" onsubmit="return confirm('{{ __('Delete this broadcast?') }}')">@csrf @method('DELETE')
                                                <button class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-12 text-center text-ink-400 text-[13px]">{{ __('No broadcasts yet.') }} <a href="{{ route('user.wechat.broadcasts.create') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Create your first one') }}</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
