@php
    use App\Models\SalesDoc;
    $badge = [
        'draft'    => 'bg-paper-200 text-ink-600',
        'sent'     => 'bg-[#E0F2FE] text-[#0369A1]',
        'accepted' => 'bg-wa-mint text-wa-deep',
        'rejected' => 'bg-accent-coral/10 text-accent-coral',
        'expired'  => 'bg-amber-100 text-amber-700',
        'invoiced' => 'bg-wa-deep/10 text-wa-deep',
    ];
    $sym = \App\Models\Currency::symbolFor($currency);
    $openDisplay = $sym . number_format($openMinor / (10 ** $exponent), $exponent);
    $routePrefix = $type === SalesDoc::TYPE_ESTIMATE ? 'estimates' : 'proposals';
@endphp
<x-layouts.user :title="$typePlural" nav-key="more" page="user-salesdocs-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Your') }} <span class="italic text-wa-deep">{{ strtolower($typePlural) }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">
                    {{ $type === SalesDoc::TYPE_ESTIMATE
                        ? __('Quote a price before you invoice. Send, get accepted, convert to a real invoice in one click.')
                        : __('Pitch scope and price. Track sent / accepted, then convert the winner straight into an invoice.') }}
                </p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <div class="text-right">
                    <div class="font-mono text-[10px] uppercase tracking-wide text-ink-400">{{ __('Open value') }}</div>
                    <div class="text-[18px] font-semibold text-wa-deep">{{ $openDisplay }}</div>
                </div>
                <button type="button" id="sd-open" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">+ {{ __('New') }} {{ strtolower($typeLabel) }}</button>
            </div>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-2.5 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif
        @if ($errors->any())<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

        <x-crm.how-to :title="$typePlural" :steps="[
            __('Click <b>+ New ' . strtolower($typeLabel) . '</b>, add the buyer name + phone, then add line items — the total adds up as you type.'),
            __('On a row, hit the green <b>Send</b> button to WhatsApp the link to the buyer from your connected number.'),
            __('The buyer opens the link and taps <b>Accept</b> (or you click <b>Accept</b> yourself). You get notified.'),
            __('On an accepted one, click <b>&rarr; Invoice</b> to turn it into a real, numbered invoice — no retyping.'),
        ]" />

        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-left text-ink-500 border-b border-paper-200 bg-paper-50">
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Number') }}</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Title / Buyer') }}</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-right">{{ __('Total') }}</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Status') }}</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide">{{ __('Valid until') }}</th>
                            <th class="px-4 py-2.5 font-mono text-[10px] uppercase tracking-wide text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($docs as $doc)
                            <tr class="border-b border-paper-100 last:border-0 hover:bg-paper-50/60">
                                <td class="px-4 py-3 font-mono text-[11.5px] text-ink-700 whitespace-nowrap">{{ $doc->number }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-ink-900">{{ $doc->title ?: '—' }}</div>
                                    <div class="text-[11px] text-ink-500">{{ $doc->buyer_name ?: ($doc->company?->name ?: '—') }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ $doc->total_display }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10.5px] font-semibold {{ $badge[$doc->status] ?? 'bg-paper-200 text-ink-600' }}">{{ ucfirst($doc->status) }}</span>
                                </td>
                                <td class="px-4 py-3 text-[11.5px] text-ink-600 whitespace-nowrap">{{ $doc->valid_until ? $doc->valid_until->format('d M Y') : '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                        <form method="POST" action="{{ route('user.' . $routePrefix . '.send', $doc->id) }}"
                                            onsubmit="return confirm('{{ __('Send this on WhatsApp to the buyer?') }}')">@csrf
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-[#25D366] text-white text-[11px] font-semibold hover:brightness-95 inline-flex items-center gap-1" title="{{ __('Send on WhatsApp via your connected number') }}">
                                                <svg viewBox="0 0 16 16" class="w-3 h-3" fill="currentColor"><path d="M8 0a8 8 0 0 0-6.9 12l-1 3.6 3.7-1A8 8 0 1 0 8 0Zm4.6 11.3c-.2.5-1.1 1-1.5 1-.4.1-.9.1-1.4-.1-.3-.1-.8-.3-1.4-.5-2.4-1.1-4-3.6-4.1-3.7-.1-.2-1-1.3-1-2.5 0-1.2.6-1.7.8-2 .2-.2.5-.3.6-.3h.5c.1 0 .3 0 .5.4l.7 1.6c0 .2.1.3 0 .4l-.3.5-.3.3c-.1.1-.2.2-.1.4.1.2.5.9 1.2 1.4.8.7 1.5 1 1.7 1 .2.1.3.1.4-.1l.6-.7c.1-.2.3-.1.4-.1l1.5.7c.2.1.4.2.4.3.1.1.1.5-.1 1Z"/></svg>
                                                {{ __('Send') }}
                                            </button>
                                        </form>
                                        <a href="{{ $doc->publicUrl() }}" target="_blank" class="px-2.5 py-1 rounded-lg border border-paper-200 text-[11px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('View') }}</a>
                                        @if (in_array($doc->status, ['draft']))
                                            <form method="POST" action="{{ route('user.' . $routePrefix . '.update', $doc->id) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="sent"><button class="px-2.5 py-1 rounded-lg bg-[#E0F2FE] text-[#0369A1] text-[11px] font-semibold hover:brightness-95">{{ __('Mark sent') }}</button></form>
                                        @endif
                                        @if (in_array($doc->status, ['draft', 'sent', 'expired']))
                                            <form method="POST" action="{{ route('user.' . $routePrefix . '.update', $doc->id) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="accepted"><button class="px-2.5 py-1 rounded-lg bg-wa-mint text-wa-deep text-[11px] font-semibold hover:brightness-95">{{ __('Accept') }}</button></form>
                                        @endif
                                        @if (in_array($doc->status, ['sent', 'accepted']) && ! $doc->invoice_id)
                                            <form method="POST" action="{{ route('user.' . $routePrefix . '.convert', $doc->id) }}" onsubmit="return confirm('{{ __('Convert to a real invoice?') }}')">@csrf<button class="px-2.5 py-1 rounded-lg bg-wa-deep text-paper-0 text-[11px] font-semibold hover:bg-wa-teal">{{ __('→ Invoice') }}</button></form>
                                        @endif
                                        <form method="POST" action="{{ route('user.' . $routePrefix . '.destroy', $doc->id) }}" onsubmit="return confirm('{{ __('Delete?') }}')">@csrf @method('DELETE')<button class="px-2 py-1 rounded-lg text-ink-400 hover:text-accent-coral" aria-label="Delete"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg></button></form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-12 text-center text-ink-400 text-[13px]">{{ __('No :type yet — create your first one.', ['type' => strtolower($typePlural)]) }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($docs->hasPages())<div>{{ $docs->links() }}</div>@endif
    </main>

    {{-- New-doc modal --}}
    <div id="sd-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink-900/40 p-4">
        <form method="POST" action="{{ route('user.' . $routePrefix . '.store') }}" class="bg-paper-0 rounded-2xl shadow-xl w-full max-w-2xl p-5 space-y-3 max-h-[92vh] overflow-y-auto">
            @csrf
            <div class="flex items-center justify-between">
                <div class="text-[15px] font-serif">{{ __('New') }} {{ strtolower($typeLabel) }}</div>
                <button type="button" id="sd-close" class="w-7 h-7 grid place-items-center rounded-lg hover:bg-paper-100 text-ink-500" aria-label="Close"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l8 8M12 4l-8 8"/></svg></button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block sm:col-span-2"><span class="text-[11px] font-semibold text-ink-700">{{ __('Title') }}</span>
                    <input name="title" maxlength="255" placeholder="{{ __('e.g. Website redesign — Phase 1') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Buyer name') }}</span>
                    <input name="buyer_name" maxlength="255" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Buyer email') }}</span>
                    <input name="buyer_email" maxlength="255" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Company') }}</span>
                    <select name="company_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"><option value="">{{ __('— none —') }}</option>@foreach ($companies as $co)<option value="{{ $co->id }}">{{ $co->name ?: ('#' . $co->id) }}</option>@endforeach</select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Valid until') }}</span>
                    <input name="valid_until" type="date" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            </div>

            {{-- Line items --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[11px] font-semibold text-ink-700">{{ __('Line items') }}</span>
                    <button type="button" id="sd-add-row" class="text-[11px] font-semibold text-wa-deep hover:underline">+ {{ __('Add row') }}</button>
                </div>
                <div id="sd-rows" class="space-y-2" data-currency="{{ $sym }}" data-exp="{{ $exponent }}"></div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Tax rate %') }}</span>
                    <input name="tax_rate" id="sd-tax" type="number" min="0" max="100" step="0.01" value="0" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <div class="text-right">
                    <div class="text-[11px] text-ink-500">{{ __('Subtotal') }} <span id="sd-subtotal" class="font-mono text-ink-700">{{ $sym }}0</span></div>
                    <div class="text-[11px] text-ink-500">{{ __('Tax') }} <span id="sd-tax-amt" class="font-mono text-ink-700">{{ $sym }}0</span></div>
                    <div class="text-[15px] font-semibold text-wa-deep mt-0.5">{{ __('Total') }} <span id="sd-total" class="font-mono">{{ $sym }}0</span></div>
                </div>
            </div>

            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Notes') }}</span>
                <textarea name="notes" rows="2" maxlength="5000" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></textarea></label>

            <div class="flex justify-end gap-2 pt-1">
                <button type="button" id="sd-cancel" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Cancel') }}</button>
                <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Create') }}</button>
            </div>
        </form>
    </div>
</x-layouts.user>
