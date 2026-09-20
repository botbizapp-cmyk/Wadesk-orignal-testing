@php
    use App\Models\Currency;
    $fmt = fn ($minor, $cur) => Currency::symbolFor((string) $cur) . number_format(((int) $minor) / 100, 2);
    $agBuckets = $aging['buckets'] ?? [];
@endphp
<x-layouts.user :title="__('Payments')" nav-key="more" page="payments-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Money') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Payment') }} <span class="italic text-wa-deep">{{ __('ledger') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Record money received, track what is outstanding, and see the aging of unpaid invoices.') }}</p>
            </div>
            <button type="button" id="pay-open" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold shrink-0">+ {{ __('Record payment') }}</button>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif

        <x-crm.how-to :steps="[
            __('When a customer pays an invoice, click <b>Record payment</b> and enter the amount (full or partial).'),
            __('The invoice flips to <b>Paid</b> on its own once the balance reaches zero.'),
            __('The <b>aging</b> panel shows who still owes you, split by how overdue they are (0-30 / 31-60 / 61-90 / 90+).'),
        ]" />

        {{-- KPIs + aging --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_1fr] gap-6 items-start">
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                    <div class="text-[10.5px] uppercase tracking-wide text-ink-500 font-semibold">{{ __('Collected (30d)') }}</div>
                    <div class="text-[26px] font-serif mt-1">{{ $fmt($collected30, $currency) }}</div>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                    <div class="text-[10.5px] uppercase tracking-wide text-ink-500 font-semibold">{{ __('Outstanding') }}</div>
                    <div class="text-[26px] font-serif mt-1 text-accent-coral">{{ $fmt($aging['total_outstanding_minor'] ?? 0, $aging['currency'] ?? $currency) }}</div>
                    <div class="text-[11px] text-ink-500 mt-0.5">{{ $aging['count'] ?? 0 }} {{ __('invoices') }}</div>
                </div>
            </div>
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Aging') }} <span class="text-[11px] font-normal text-ink-400">{{ __('(days since issue)') }}</span></div>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (['0-30','31-60','61-90','90+'] as $b)
                        <div class="rounded-xl border border-paper-200 p-3 text-center">
                            <div class="text-[10px] uppercase tracking-wide text-ink-500 font-semibold">{{ $b }}</div>
                            <div class="text-[14px] font-mono mt-1 {{ $b === '90+' ? 'text-accent-coral' : 'text-ink-900' }}">{{ $fmt($agBuckets[$b] ?? 0, $aging['currency'] ?? $currency) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6 items-start">
            {{-- Ledger --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
                <div class="px-5 py-3 border-b border-paper-200 text-[13px] font-semibold text-ink-900">{{ __('Recent payments') }}</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead><tr class="text-ink-500 text-[10.5px] uppercase tracking-wide">
                            <th class="text-left font-semibold px-5 py-2">{{ __('Date') }}</th>
                            <th class="text-left font-semibold px-2 py-2">{{ __('From') }}</th>
                            <th class="text-left font-semibold px-2 py-2">{{ __('Method') }}</th>
                            <th class="text-left font-semibold px-2 py-2">{{ __('Invoice') }}</th>
                            <th class="text-right font-semibold px-5 py-2">{{ __('Amount') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($payments as $p)
                            <tr class="border-t border-paper-100">
                                <td class="px-5 py-2.5 text-ink-600 whitespace-nowrap">{{ optional($p->paid_at)->format('d M Y') ?: '—' }}</td>
                                <td class="px-2 py-2.5">{{ $p->company?->name ?: ($p->contact?->name ?: '—') }}</td>
                                <td class="px-2 py-2.5"><span class="font-mono text-[11px] px-2 py-0.5 rounded-full bg-paper-100 text-ink-700">{{ $p->method }}</span></td>
                                <td class="px-2 py-2.5 font-mono text-[11.5px] text-ink-600">{{ $p->invoice?->invoice_number ?: '—' }}</td>
                                <td class="px-5 py-2.5 text-right font-mono text-wa-deep">{{ $p->amount_display }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-8 text-center text-ink-500 text-[12.5px]">{{ __('No payments recorded yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Outstanding --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <div class="text-[13px] font-semibold text-ink-900 mb-3">{{ __('Outstanding invoices') }}</div>
                <div class="space-y-2 max-h-[420px] overflow-y-auto">
                    @forelse ($outstanding as $inv)
                        <div class="flex items-center justify-between gap-2 rounded-xl border border-paper-200 px-3 py-2">
                            <div class="min-w-0">
                                <div class="font-mono text-[11.5px] text-ink-800 truncate">{{ $inv->invoice_number }}</div>
                                <div class="text-[10.5px] text-ink-500">{{ __('due') }} {{ $fmt($inv->outstanding_minor, $inv->currency) }}</div>
                            </div>
                            <button type="button" class="pay-quick text-[11px] text-wa-deep font-semibold hover:underline shrink-0"
                                data-invoice="{{ $inv->id }}" data-number="{{ $inv->invoice_number }}"
                                data-outstanding="{{ number_format($inv->outstanding_minor / 100, 2, '.', '') }}"
                                data-currency="{{ $inv->currency }}">{{ __('Record') }}</button>
                        </div>
                    @empty
                        <div class="text-[12.5px] text-ink-500 py-4 text-center">{{ __('Nothing outstanding. Nice.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </main>

    {{-- Record-payment modal --}}
    <div id="pay-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink-900/40 p-4">
        <form method="POST" action="{{ route('user.payments.store') }}" class="bg-paper-0 rounded-2xl shadow-xl w-full max-w-md p-5 space-y-3">
            @csrf
            <div class="flex items-center justify-between">
                <div class="text-[15px] font-serif">{{ __('Record payment') }}</div>
                <button type="button" id="pay-close" class="w-7 h-7 grid place-items-center rounded-lg hover:bg-paper-100 text-ink-500" aria-label="Close">
                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>
            <input type="hidden" name="invoice_id" id="pay-invoice">
            <div id="pay-inv-note" class="hidden text-[11.5px] text-ink-500 font-mono"></div>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Amount') }}</span>
                    <input name="amount" id="pay-amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Currency') }}</span>
                    <input name="currency" id="pay-currency" value="{{ $currency }}" maxlength="3" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono uppercase focus:outline-none focus:border-wa-deep"></label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Method') }}</span>
                    <select name="method" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        @foreach (['manual'=>'Manual','cash'=>'Cash','bank'=>'Bank','card'=>'Card','upi'=>'UPI'] as $v=>$l)<option value="{{ $v }}">{{ __($l) }}</option>@endforeach
                    </select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Paid on') }}</span>
                    <input name="paid_at" type="date" value="{{ now()->format('Y-m-d') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Reference') }}</span>
                <input name="reference" maxlength="191" placeholder="{{ __('Txn id / cheque no.') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Note') }}</span>
                <input name="note" maxlength="2000" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" id="pay-cancel" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Cancel') }}</button>
                <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Record') }}</button>
            </div>
        </form>
    </div>
</x-layouts.user>
