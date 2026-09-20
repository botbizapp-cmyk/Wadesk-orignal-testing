@php
    use App\Models\Currency;
    $sym = Currency::symbolFor($currency);
    $md = fn ($minor) => $sym . number_format(((int) $minor) / 100, 2);
    $agB = $aging['buckets'] ?? [];
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #1c1a17; font-size: 12px; margin: 28px; }
    h1 { font-size: 20px; margin: 0 0 2px; }
    .muted { color: #6b6459; font-size: 11px; }
    .grid { width: 100%; margin-top: 18px; }
    .card { border: 1px solid #e3ddd2; border-radius: 6px; padding: 10px 12px; }
    .label { font-size: 9px; text-transform: uppercase; letter-spacing: .06em; color: #6b6459; }
    .val { font-size: 17px; margin-top: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { text-align: left; padding: 7px 9px; border-bottom: 1px solid #eee7db; font-size: 11px; }
    th { background: #f5f1ea; text-transform: uppercase; font-size: 9px; letter-spacing: .05em; color: #6b6459; }
    .right { text-align: right; }
    .accent { color: #0b3d2e; }
    .coral { color: #b3402e; }
</style>
</head>
<body>
    <h1>{{ $brand }} — {{ __('Revenue report') }}</h1>
    <div class="muted">{{ __('Generated') }} {{ $generatedAt }} · {{ __('Currency') }} {{ $currency }}</div>

    <table class="grid">
        <tr>
            <td width="25%"><div class="card"><div class="label">{{ __('Collected (30d)') }}</div><div class="val accent">{{ $md($collected30) }}</div></div></td>
            <td width="25%"><div class="card"><div class="label">{{ __('Collected (all)') }}</div><div class="val">{{ $md($collectedAll) }}</div></div></td>
            <td width="25%"><div class="card"><div class="label">{{ __('Outstanding') }}</div><div class="val coral">{{ $md($outstanding) }}</div></div></td>
            <td width="25%"><div class="card"><div class="label">{{ __('Tax collected') }}</div><div class="val">{{ $md($taxCollected) }}</div></div></td>
        </tr>
    </table>

    <table>
        <thead><tr><th>{{ __('Month') }}</th><th class="right">{{ __('Collected') }}</th></tr></thead>
        <tbody>
        @foreach ($collectedByMonth as $mo)
            <tr><td>{{ $mo['label'] }}</td><td class="right">{{ $md($mo['minor']) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <table>
        <thead><tr><th colspan="2">{{ __('Receivables aging') }} — {{ $aging['count'] ?? 0 }} {{ __('invoices') }}</th></tr></thead>
        <tbody>
        @foreach (['0-30','31-60','61-90','90+'] as $b)
            <tr><td>{{ $b }} {{ __('days') }}</td><td class="right {{ $b === '90+' ? 'coral' : '' }}">{{ $md($agB[$b] ?? 0) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <div class="muted" style="margin-top: 24px;">{{ __('Invoices') }}: {{ $invoicesPaid }} {{ __('paid') }}, {{ $invoicesOpen }} {{ __('open') }}.</div>
</body>
</html>
