@php
    use App\Support\MoneyFormat;
    $cur = (string) $invoice->currency;
    $money = fn ($m) => MoneyFormat::display((int) $m, $cur);
    $invoice->loadMissing('items', 'taxSummary');
@endphp
<div class="space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div><div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ str_replace('_',' ',$invoice->doc_type) }}</div><div class="font-serif text-[22px] leading-none mt-0.5">{{ $invoice->invoice_number }}</div></div>
        <div class="text-right"><div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Total') }}</div><div class="font-serif text-[24px] text-wa-deep">{{ $money($invoice->total_minor) }}</div></div>
    </div>
    <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card p-5 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div><div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('Customer') }}</div><div class="font-semibold text-[14px]">{{ $invoice->buyer_name ?: '—' }}</div><div class="text-[12px] text-ink-500">{{ $invoice->buyer_phone }} {{ $invoice->buyer_email }}</div></div>
            <span class="font-mono text-[10px] uppercase px-2 py-1 rounded-full bg-paper-100 text-ink-600" title="{{ $invoice->send_reason }}">{{ str_replace('_',' ',$invoice->send_status) }}</span>
        </div>
        <div class="border border-paper-200 rounded-xl overflow-hidden">
            <table class="w-full text-[12.5px]">
                <thead class="bg-paper-50 text-left font-mono text-[10px] uppercase text-ink-500"><tr><th class="px-3 py-2">{{ __('Item') }}</th><th class="px-3 py-2 text-right">{{ __('Qty') }}</th><th class="px-3 py-2 text-right">{{ __('Amount') }}</th></tr></thead>
                <tbody class="divide-y divide-paper-100">
                    @foreach ($invoice->items as $it)<tr><td class="px-3 py-2">{{ $it->description }}</td><td class="px-3 py-2 text-right">{{ rtrim(rtrim((string)$it->qty,'0'),'.') }}</td><td class="px-3 py-2 text-right">{{ $money($it->line_subtotal_minor) }}</td></tr>@endforeach
                </tbody>
            </table>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="{{ route('user.invoices.pdf', $invoice->id) }}" target="_blank" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Download PDF') }}</a>
            <a href="{{ $invoice->publicUrl() }}" target="_blank" class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium">{{ __('Public link') }}</a>
            <form method="POST" action="{{ route('user.invoices.resend', $invoice->id) }}">@csrf<button class="px-4 py-2 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 text-[12.5px] font-medium">{{ __('Send on WhatsApp') }}</button></form>
        </div>
        @if ($invoice->send_status === 'send_failed' && $invoice->send_reason)
            <div class="text-[11.5px] text-accent-coral">{{ __('Delivery issue') }}: {{ str_replace('_',' ',$invoice->send_reason) }}</div>
        @endif
    </div>
</div>
