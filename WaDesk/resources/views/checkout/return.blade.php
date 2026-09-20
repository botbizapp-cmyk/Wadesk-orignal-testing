@php
    $brand = \App\Support\FormatSettings::class;
    $amount = $order ? \App\Support\FormatSettings::formatIn(((int) $order->total_minor) / 100, $order->currency_code) : '';
    $ref    = $order ? ('WA-' . $order->id) : '';
    $cfg = [
        'paid'     => ['#12805c', '#e7f6ef', 'Payment successful', 'Thank you! Your payment has been received.'],
        'pending'  => ['#b26a00', '#fdf3e3', 'Payment processing', 'If you completed the payment, this will confirm shortly. You can safely close this page.'],
        'cancelled'=> ['#6b7280', '#f2f3f5', 'Payment cancelled', 'No charge was made. You can try again from WhatsApp whenever you are ready.'],
        'notfound' => ['#6b7280', '#f2f3f5', 'Order not found', 'We could not find this order. Please use the latest link sent to you on WhatsApp.'],
    ][$state] ?? ['#6b7280', '#f2f3f5', 'Checkout', ''];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $cfg[2] }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
               background: #f6f7f5; color: #1c2b28; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; }
        .card { width: 100%; max-width: 380px; background: #fff; border: 1px solid #e6e8e4; border-radius: 18px;
                box-shadow: 0 8px 30px rgba(20,40,35,.08); padding: 28px 24px; text-align: center; }
        .badge { width: 60px; height: 60px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
                 background: {{ $cfg[1] }}; margin-bottom: 14px; }
        .badge svg { width: 30px; height: 30px; stroke: {{ $cfg[0] }}; }
        h1 { font-size: 20px; margin: 4px 0 6px; color: {{ $cfg[0] }}; }
        p.sub { font-size: 13.5px; line-height: 1.5; color: #55635f; margin: 0 0 18px; }
        .row { display: flex; justify-content: space-between; font-size: 13px; padding: 9px 0; border-top: 1px solid #eef0ec; }
        .row span:first-child { color: #8a938f; }
        .row span:last-child { font-weight: 600; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .amt { font-size: 22px; font-weight: 700; margin: 8px 0 2px; }
        .hint { font-size: 11.5px; color: #98a09c; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">
            @if ($state === 'paid')
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            @elseif ($state === 'pending')
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            @else
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            @endif
        </span>
        <h1>{{ $cfg[2] }}</h1>
        <p class="sub">{{ $cfg[3] }}</p>

        @if ($order)
            @if ($amount)
                <div class="amt" style="color: {{ $cfg[0] }}">{{ $amount }}</div>
            @endif
            <div class="row"><span>Order</span><span>{{ $ref }}</span></div>
            @if ($order->customer_name)
                <div class="row"><span>Name</span><span>{{ $order->customer_name }}</span></div>
            @endif
            <div class="row"><span>Status</span><span style="color: {{ $cfg[0] }}">{{ ucfirst($order->status) }}</span></div>
        @endif

        <div class="hint">Return to your WhatsApp chat for your receipt.</div>
    </div>
</body>
</html>
