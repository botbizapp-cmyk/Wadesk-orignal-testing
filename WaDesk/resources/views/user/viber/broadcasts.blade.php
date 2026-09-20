@php
    /** @var \Illuminate\Support\Collection $broadcasts */
    /** @var \Illuminate\Support\Collection $channels */
    /** @var array $stats */
    $statusPill = fn ($s) => match ($s) {
        'sending' => ['bg-accent-amber/15 text-[#7B5A14]', __('Sending')],
        'done'    => ['bg-wa-mint text-wa-deep', __('Done')],
        'failed'  => ['bg-accent-coral/10 text-accent-coral', __('Failed')],
        default   => ['bg-paper-100 text-ink-600', __('Draft')],
    };
@endphp

<x-layouts.user :title="__('Viber broadcasts')" nav-key="more" page="user-viber-broadcasts">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">

        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div class="min-w-0">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Viber') }} · {{ __('Broadcasts') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[32px] sm:text-[40px] leading-none">{{ __('Viber') }} <span class="italic" style="color:#7360F2">{{ __('broadcasts') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Message subscribed users in batches of up to 300. Only users who have opened a chat with your account can be reached.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('user.viber.index') }}" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('Channels') }}</a>
                @unless ($channels->isEmpty())
                    <a href="{{ route('user.viber.broadcasts.create') }}" class="px-4 py-2 rounded-full text-white text-[12px] font-semibold inline-flex items-center gap-2" style="background:#7360F2">
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
                <span class="mx-auto w-12 h-12 rounded-2xl grid place-items-center mb-3 text-white font-bold" style="background:#7360F2">V</span>
                <p class="text-[13.5px] text-ink-700">{{ __('Connect a Viber channel first.') }}</p>
                <a href="{{ route('user.viber.index') }}" class="mt-3 inline-flex px-4 py-2 rounded-full text-white text-[12.5px] font-semibold" style="background:#7360F2">{{ __('Connect a channel') }}</a>
            </div>
        @else
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ([[__('Sent'), $stats['sent']], [__('Audience'), $stats['audience']], [__('Failed'), $stats['failed']], [__('Sending'), $stats['sending']]] as [$lbl, $val])
                    <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                        <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ $lbl }}</div>
                        <div class="mt-2 font-serif text-[30px] leading-none">{{ number_format($val) }}</div>
                    </div>
                @endforeach
            </div>

            <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]" id="viber-broadcasts">
                        <thead>
                            <tr class="text-left text-ink-500 border-b border-paper-200 bg-paper-50/50">
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Broadcast') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide text-right">{{ __('Recipients') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Progress') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Status') }}</th>
                                <th class="px-5 py-2.5 font-mono text-[10px] uppercase tracking-wide text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($broadcasts as $b)
                                @php [$cls, $lbl] = $statusPill($b->status); @endphp
                                <tr class="border-b border-paper-100 last:border-0 hover:bg-paper-50/60" data-bcast="{{ $b->id }}">
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-ink-900">{{ $b->name }}</div>
                                        <div class="text-[10.5px] text-ink-500 font-mono mt-0.5">{{ $b->created_at?->format('d M Y · H:i') }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono">{{ number_format($b->total) }}</td>
                                    <td class="px-5 py-3 min-w-[160px]">
                                        <div class="h-1.5 rounded-full bg-paper-100 overflow-hidden"><div class="h-full rounded-full bg-wa-deep transition-all" style="width:{{ $b->progress() }}%" data-bcast-bar></div></div>
                                        <div class="mt-1 text-[10.5px] font-mono text-ink-500"><span data-bcast-sent>{{ $b->sent }}</span> {{ __('sent') }} · <span data-bcast-failed>{{ $b->failed }}</span> {{ __('failed') }}</div>
                                    </td>
                                    <td class="px-5 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-mono {{ $cls }}" data-bcast-status>{{ $lbl }}</span></td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center justify-end gap-1.5">
                                            @if (in_array($b->status, ['draft', 'sending'], true))
                                                <form method="POST" action="{{ route('user.viber.broadcasts.start', $b->id) }}" data-bcast-start>@csrf
                                                    <button type="submit" class="px-3 py-1 rounded-full text-white text-[11px] font-semibold" style="background:#7360F2">{{ $b->status === 'sending' ? __('Resume') : __('Start') }}</button>
                                                </form>
                                            @elseif ($b->failed > 0)
                                                <form method="POST" action="{{ route('user.viber.broadcasts.retry', $b->id) }}">@csrf
                                                    <button type="submit" class="px-3 py-1 rounded-full border border-paper-200 text-[11px] font-medium hover:bg-paper-50">{{ __('Retry') }}</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ route('user.viber.broadcasts.destroy', $b->id) }}" onsubmit="return confirm('{{ __('Delete this broadcast?') }}')">@csrf @method('DELETE')
                                                <button type="submit" class="w-7 h-7 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-12 text-center text-ink-400 text-[13px]">{{ __('No broadcasts yet.') }} <a href="{{ route('user.viber.broadcasts.create') }}" class="text-wa-deep font-semibold hover:underline">{{ __('Create your first one') }}</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </main>
</x-layouts.user>
