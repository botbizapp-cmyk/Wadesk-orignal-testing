@php
    use App\Models\Currency;
    $sym = Currency::symbolFor($currency);
    $statusColor = ['won' => '#0b7a4b', 'open' => '#0b3d2e', 'lost' => '#b3402e'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, "Segoe UI", DejaVu Sans, sans-serif; color: #201d17; background: #f4f1ea; }
    .deck { max-width: 820px; margin: 0 auto; padding: 0 0 48px; }
    .cover { background: linear-gradient(135deg, #0b3d2e, #128c5a); color: #fff; padding: 56px 44px 48px; border-radius: 0 0 20px 20px; }
    .cover .eyebrow { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; opacity: .8; }
    .cover h1 { font-family: Georgia, serif; font-size: 34px; font-weight: 600; margin-top: 10px; line-height: 1.1; }
    .cover .sub { margin-top: 10px; font-size: 14px; opacity: .9; }
    .section { background: #fff; border: 1px solid #e6dfd3; border-radius: 14px; margin: 22px 44px 0; padding: 24px 26px; }
    .section h2 { font-family: Georgia, serif; font-size: 19px; font-weight: 600; margin-bottom: 16px; }
    .stats { display: flex; flex-wrap: wrap; gap: 12px; }
    .stat { flex: 1 1 140px; border: 1px solid #eee7db; border-radius: 10px; padding: 12px 14px; }
    .stat .l { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #6b6459; }
    .stat .v { font-size: 20px; font-family: Georgia, serif; margin-top: 4px; color: #0b3d2e; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 9px 6px; border-bottom: 1px solid #eee7db; font-size: 13px; }
    th { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: #6b6459; }
    .pill { font-size: 10px; padding: 2px 8px; border-radius: 999px; color: #fff; }
    .right { text-align: right; font-variant-numeric: tabular-nums; }
    .foot { text-align: center; color: #9a9184; font-size: 11px; margin-top: 26px; }
</style>
</head>
<body>
    <div class="deck">
        <div class="cover">
            <div class="eyebrow">{{ $brand }} · {{ __('Client brief') }}</div>
            <h1>{{ $subject }}</h1>
            <div class="sub">{{ $summary }} · {{ __('Generated') }} {{ $generatedAt }}</div>
        </div>

        <div class="section">
            <h2>{{ __('Snapshot') }}</h2>
            <div class="stats">
                @foreach ($stats as [$label, $value])
                    <div class="stat"><div class="l">{{ $label }}</div><div class="v">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>

        @if (! empty($deals))
            <div class="section">
                <h2>{{ __('Deals') }}</h2>
                <table>
                    <thead><tr><th>{{ __('Deal') }}</th><th>{{ __('Status') }}</th><th class="right">{{ __('Value') }}</th></tr></thead>
                    <tbody>
                        @foreach ($deals as $d)
                            <tr>
                                <td>{{ $d['title'] }}</td>
                                <td><span class="pill" style="background: {{ $statusColor[$d['status']] ?? '#6b6459' }}">{{ $d['status'] }}</span></td>
                                <td class="right">{{ $sym }}{{ number_format(((int) $d['value']) / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="foot">{{ $brand }} — {{ __('Prepared') }} {{ $generatedAt }}.</div>
    </div>
</body>
</html>
