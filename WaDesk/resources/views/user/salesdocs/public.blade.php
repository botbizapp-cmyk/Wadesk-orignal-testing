@php
    use App\Models\Currency;
    $sym = Currency::symbolFor($doc->currency);
    $exp = (int) $doc->currency_exponent;
    $fmt = fn ($minor) => $sym . number_format(((int) $minor) / (10 ** $exp), $exp);
    $items = $doc->items_json ?? [];
    $statusColor = [
        'draft' => '#6b6559', 'sent' => '#0369a1', 'accepted' => '#0b7a4b',
        'rejected' => '#b3402e', 'expired' => '#b45309', 'invoiced' => '#0b3d2e',
    ];
    $brand = function_exists('brand_name') ? brand_name() : config('app.name');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $doc->typeLabel() }} {{ $doc->number }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, "Segoe UI", DejaVu Sans, sans-serif; color: #201d17; background: #f4f1ea; padding: 0 0 48px; }
    .wrap { max-width: 780px; margin: 0 auto; }
    .cover { background: linear-gradient(135deg, #0b3d2e, #128c5a); color: #fff; padding: 44px 40px 36px; border-radius: 0 0 20px 20px; }
    .eyebrow { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; opacity: .85; }
    .cover h1 { font-family: Georgia, serif; font-size: 30px; font-weight: 600; margin-top: 8px; line-height: 1.15; }
    .cover .meta { margin-top: 14px; font-size: 13px; opacity: .92; display: flex; gap: 18px; flex-wrap: wrap; }
    .pill { display: inline-block; padding: 3px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #fff; }
    .card { background: #fff; border: 1px solid #e6dfd3; border-radius: 14px; margin: 22px 40px 0; padding: 22px 24px; }
    .card h2 { font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: #6b6559; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    th { text-align: left; font-size: 10.5px; letter-spacing: .06em; text-transform: uppercase; color: #8a8478; padding: 0 0 8px; border-bottom: 1px solid #ece7dd; }
    td { padding: 10px 0; border-bottom: 1px solid #f0ece3; }
    td.r, th.r { text-align: right; }
    .totals { margin-top: 14px; margin-left: auto; width: 260px; font-size: 13.5px; }
    .totals .row { display: flex; justify-content: space-between; padding: 5px 0; }
    .totals .grand { font-weight: 700; font-size: 17px; color: #0b3d2e; border-top: 2px solid #0b3d2e; margin-top: 6px; padding-top: 10px; }
    .buyer { font-size: 13.5px; line-height: 1.6; }
    .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 4px; }
    .btn { border: 0; cursor: pointer; font-size: 14px; font-weight: 700; padding: 12px 22px; border-radius: 999px; }
    .btn-accept { background: #0b7a4b; color: #fff; }
    .btn-decline { background: #fff; color: #b3402e; border: 1px solid #e6cfca; }
    .note { font-size: 12.5px; color: #8a8478; margin-top: 10px; }
    .banner { border-radius: 12px; padding: 14px 18px; font-size: 13.5px; font-weight: 600; margin: 22px 40px 0; }
    .banner-ok { background: #e4f5ec; color: #0b7a4b; border: 1px solid #b6e2c9; }
    .banner-no { background: #fbeae6; color: #b3402e; border: 1px solid #f0cfc6; }
    .foot { text-align: center; font-size: 11.5px; color: #8a8478; margin-top: 26px; }
    @media (max-width: 640px) { .cover, .card { margin-left: 14px; margin-right: 14px; padding-left: 20px; padding-right: 20px; } .cover { border-radius: 0; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="cover">
        <div class="eyebrow">{{ $brand }} · {{ $doc->typeLabel() }}</div>
        <h1>{{ $doc->title ?: $doc->typeLabel() }}</h1>
        <div class="meta">
            <span>{{ $doc->number }}</span>
            <span class="pill" style="color: {{ $statusColor[$doc->status] ?? '#333' }}">{{ ucfirst($doc->status) }}</span>
            @if ($doc->valid_until)<span>Valid until {{ $doc->valid_until->format('d M Y') }}</span>@endif
        </div>
    </div>

    @if ($doc->buyer_name || $doc->company?->name || $doc->buyer_email)
        <div class="card">
            <h2>{{ __('Prepared for') }}</h2>
            <div class="buyer">
                @if ($doc->buyer_name)<strong>{{ $doc->buyer_name }}</strong><br>@endif
                @if ($doc->company?->name){{ $doc->company->name }}<br>@endif
                @if ($doc->buyer_email){{ $doc->buyer_email }}<br>@endif
                @if ($doc->buyer_phone){{ $doc->buyer_phone }}@endif
            </div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Line items') }}</h2>
        <table>
            <thead><tr><th>{{ __('Description') }}</th><th class="r">{{ __('Qty') }}</th><th class="r">{{ __('Unit') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                @forelse ($items as $it)
                    <tr>
                        <td>{{ $it['description'] ?? '' }}</td>
                        <td class="r">{{ rtrim(rtrim(number_format((float) ($it['qty'] ?? 0), 2), '0'), '.') }}</td>
                        <td class="r">{{ $fmt($it['unit_price_minor'] ?? 0) }}</td>
                        <td class="r">{{ $fmt($it['line_total_minor'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="color:#8a8478;padding:16px 0;">{{ __('No items.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="totals">
            <div class="row"><span>{{ __('Subtotal') }}</span><span>{{ $fmt($doc->subtotal_minor) }}</span></div>
            @if ($doc->tax_minor > 0)<div class="row"><span>{{ __('Tax') }} ({{ rtrim(rtrim(number_format($doc->tax_rate_bp / 100, 2), '0'), '.') }}%)</span><span>{{ $fmt($doc->tax_minor) }}</span></div>@endif
            <div class="row grand"><span>{{ __('Total') }}</span><span>{{ $fmt($doc->total_minor) }}</span></div>
        </div>
    </div>

    @if ($doc->notes)
        <div class="card"><h2>{{ __('Notes') }}</h2><div class="buyer" style="white-space:pre-wrap;">{{ $doc->notes }}</div></div>
    @endif

    @php
        $justDecided = session('decided');
        $openStates = ['draft', 'sent', 'expired'];
        $isOpen = in_array($doc->status, $openStates, true);
    @endphp

    {{-- Confirmation banner after the customer acts --}}
    @if ($justDecided === 'accepted' || $doc->status === 'accepted')
        <div class="banner banner-ok">✓ {{ __('You accepted this :type. :brand has been notified and will follow up.', ['type' => strtolower($doc->typeLabel()), 'brand' => $brand]) }}</div>
    @elseif ($justDecided === 'rejected' || $doc->status === 'rejected')
        <div class="banner banner-no">{{ __('This :type was declined. If this was a mistake, please contact :brand.', ['type' => strtolower($doc->typeLabel()), 'brand' => $brand]) }}</div>
    @elseif ($doc->status === 'invoiced')
        <div class="banner banner-ok">✓ {{ __('Accepted — an invoice has been issued for this :type.', ['type' => strtolower($doc->typeLabel())]) }}</div>
    @endif

    {{-- Customer decision buttons (only while still open) --}}
    @if ($isOpen)
        <div class="card">
            <h2>{{ __('Your decision') }}</h2>
            <div class="actions">
                <form method="POST" action="{{ route('salesdoc.public.accept', $doc->public_token) }}">@csrf
                    <button type="submit" class="btn btn-accept">✓ {{ __('Accept this :type', ['type' => strtolower($doc->typeLabel())]) }}</button>
                </form>
                <form method="POST" action="{{ route('salesdoc.public.decline', $doc->public_token) }}" onsubmit="return confirm('{{ __('Decline this :type?', ['type' => strtolower($doc->typeLabel())]) }}')">@csrf
                    <button type="submit" class="btn btn-decline">{{ __('Decline') }}</button>
                </form>
            </div>
            <div class="note">{{ __('By accepting you confirm the scope and pricing above.') }}</div>
        </div>
    @endif

    <div class="foot">{{ __('This :type was generated by :brand.', ['type' => strtolower($doc->typeLabel()), 'brand' => $brand]) }}</div>
</div>
</body>
</html>
