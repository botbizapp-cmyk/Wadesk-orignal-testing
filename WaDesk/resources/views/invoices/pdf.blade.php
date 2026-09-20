@php
    use App\Support\MoneyFormat;
    $cur = (string) $invoice->currency;
    $money = fn ($minor) => MoneyFormat::display((int) $minor, $cur);
    $isTax = $invoice->doc_type === 'tax_invoice';
    $title = match ($invoice->doc_type) {
        'tax_invoice' => 'Tax Invoice',
        'proforma'    => 'Proforma Invoice',
        'credit_note' => 'Credit Note',
        default       => 'Receipt',
    };
    $seller = is_array($invoice->seller_snapshot_json) ? $invoice->seller_snapshot_json : [];
    $brand  = $settings->brand_color ?: '#0B3D2E';
    $issued = $invoice->issued_at ? \Illuminate\Support\Carbon::parse($invoice->issued_at)->timezone(safe_timezone(config('app.timezone'), 'Asia/Calcutta'))->format('d M Y') : '';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { color: #1c2b28; font-size: 12px; margin: 0; }
    .wrap { padding: 32px 34px; }
    .row { width: 100%; }
    .row td { vertical-align: top; }
    h1 { font-size: 22px; margin: 0 0 2px; color: {{ $brand }}; }
    .muted { color: #6b7a76; }
    .mono { font-family: DejaVu Sans Mono, monospace; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
    table.items th { background: {{ $brand }}; color: #fff; text-align: left; padding: 7px 8px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; }
    table.items td { padding: 7px 8px; border-bottom: 1px solid #eceeeb; font-size: 11.5px; }
    .r { text-align: right; }
    .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
    .totals td { padding: 4px 8px; font-size: 12px; }
    .totals .grand td { border-top: 2px solid {{ $brand }}; font-weight: bold; font-size: 14px; padding-top: 8px; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 10px; background: #e8f2ee; color: {{ $brand }}; text-transform: uppercase; letter-spacing: .5px; }
    .foot { margin-top: 26px; padding-top: 12px; border-top: 1px solid #eceeeb; font-size: 10.5px; color: #6b7a76; }
    .logo { max-height: 46px; }
</style>
</head>
<body>
<div class="wrap">
    <table class="row"><tr>
        <td style="width:60%">
            @if ($logoData)
                <img src="{{ $logoData }}" class="logo" alt=""><br>
            @endif
            <div style="font-size:15px; font-weight:bold; margin-top:6px">{{ $seller['name'] ?? brand_name() }}</div>
            @if (!empty($seller['address']))<div class="muted">{!! nl2br(e($seller['address'])) !!}</div>@endif
            @if (!empty($seller['tax_id']))<div class="muted">{{ $settings->tax_label ?: 'Tax ID' }}: {{ $seller['tax_id'] }}</div>@endif
            @if (!empty($seller['reg_no']))<div class="muted">{{ __('Reg. No') }}: {{ $seller['reg_no'] }}</div>@endif
            @foreach (($seller['extra'] ?? []) as $ex)<div class="muted">{{ $ex['label'] }}: {{ $ex['value'] }}</div>@endforeach
            @if (!empty($seller['phone']))<div class="muted">{{ $seller['phone'] }}</div>@endif
            @if (!empty($seller['email']))<div class="muted">{{ $seller['email'] }}</div>@endif
        </td>
        <td style="width:40%" class="r">
            <h1>{{ $title }}</h1>
            <div class="mono">{{ $invoice->invoice_number }}</div>
            <div class="muted">Date: {{ $issued }}</div>
            <div style="margin-top:6px"><span class="badge">{{ ucfirst($invoice->status) }}</span></div>
        </td>
    </tr></table>

    <table class="row" style="margin-top:20px"><tr>
        <td style="width:50%">
            <div class="muted" style="text-transform:uppercase; font-size:10px; letter-spacing:.5px">Bill to</div>
            <div style="font-weight:bold">{{ $invoice->buyer_name ?: 'Customer' }}</div>
            @if ($invoice->buyer_phone)<div class="muted">{{ $invoice->buyer_phone }}</div>@endif
            @if ($invoice->buyer_email)<div class="muted">{{ $invoice->buyer_email }}</div>@endif
            @if (!empty($invoice->billing_json['address']))<div class="muted">{!! nl2br(e($invoice->billing_json['address'])) !!}</div>@endif
        </td>
        <td style="width:50%" class="r">
            @if ($invoice->external_order_number)<div class="muted">Order: {{ $invoice->external_order_number }}</div>@endif
        </td>
    </tr></table>

    <table class="items">
        <thead><tr>
            <th>Description</th>
            @if ($isTax)<th>HSN/SAC</th>@endif
            <th class="r">Qty</th>
            <th class="r">Unit</th>
            @if ($isTax)<th class="r">Tax %</th>@endif
            <th class="r">Amount</th>
        </tr></thead>
        <tbody>
        @foreach ($invoice->items as $it)
            <tr>
                <td>{{ $it->description }}@if($it->sku)<br><span class="muted mono" style="font-size:9.5px">{{ $it->sku }}</span>@endif</td>
                @if ($isTax)<td>{{ $it->hsn_sac ?: '—' }}</td>@endif
                <td class="r">{{ rtrim(rtrim((string) $it->qty, '0'), '.') }}</td>
                <td class="r">{{ $money($it->unit_price_minor) }}</td>
                @if ($isTax)<td class="r">{{ $it->tax_rate !== null ? rtrim(rtrim((string) $it->tax_rate, '0'), '.').'%' : '—' }}</td>@endif
                <td class="r">{{ $money($it->line_subtotal_minor) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="r">{{ $money($invoice->subtotal_minor) }}</td></tr>
        @if ($invoice->discount_minor > 0)<tr><td>Discount</td><td class="r">-{{ $money($invoice->discount_minor) }}</td></tr>@endif
        @if ($invoice->shipping_minor > 0)<tr><td>Shipping</td><td class="r">{{ $money($invoice->shipping_minor) }}</td></tr>@endif
        @if ($isTax)
            @foreach ($invoice->taxSummary as $t)
                <tr><td>{{ $t->tax_label }}{{ $t->rate !== null ? ' ('.rtrim(rtrim((string) $t->rate, '0'), '.').'%)' : '' }}</td><td class="r">{{ $money($t->amount_minor) }}</td></tr>
            @endforeach
        @endif
        <tr class="grand"><td>Total</td><td class="r">{{ $money($invoice->total_minor) }}</td></tr>
    </table>

    @if (!empty($sigData))
        <table class="row" style="margin-top:30px"><tr>
            <td style="width:60%"></td>
            <td style="width:40%" class="r">
                <img src="{{ $sigData }}" style="max-height:52px; max-width:180px" alt=""><br>
                <div style="border-top:1px solid #cdd5d2; display:inline-block; padding-top:3px; min-width:150px">{{ $seller['signature_label'] ?? 'Authorised signatory' }}</div>
            </td>
        </tr></table>
    @endif

    <div class="foot">
        @if ($isTax)<div>{{ $invoice->tax_inclusive ? 'Prices are tax inclusive.' : 'Tax exclusive.' }}</div>@endif
        @if ($settings->footer_note)<div>{{ $settings->footer_note }}</div>@endif
        @if ($invoice->buyer_email || $settings->support_email)<div>For queries: {{ $settings->support_email ?: $invoice->buyer_email }}</div>@endif
        <div class="mono" style="margin-top:6px; font-size:9px; color:#9aa8a3">{{ $invoice->public_token }}</div>
    </div>
</div>
</body>
</html>
