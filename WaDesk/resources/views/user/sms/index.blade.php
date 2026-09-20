<x-layouts.user :title="__('SMS')" nav-key="sms" page="user-sms">
    @php
        $inboundUrl = preg_replace('#^http://#i', 'https://', url('/api/sms/inbound'));
        $statusUrl  = preg_replace('#^http://#i', 'https://', url('/api/sms/status'));
        $activeCount = $senders->where('status', \App\Models\WaProviderConfig::STATUS_CONNECTED)->count();
    @endphp

    @if (session('status'))
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pt-4">
            <div class="px-4 py-2.5 rounded-xl bg-wa-bubble border border-wa-green/30 text-[12.5px] text-wa-deep flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m4 8 3 3 5-6" /></svg>
                {{ session('status') }}
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pt-4">
            <div class="px-4 py-2.5 rounded-xl bg-accent-coral/10 border border-accent-coral/30 text-[12.5px] text-accent-coral flex items-center gap-2">
                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 5v3M8 11v.01" /><circle cx="8" cy="8" r="6" /></svg>
                {{ session('error') }}
            </div>
        </div>
    @endif
    @if ($errors->any())
        <div class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 pt-4">
            <div class="px-4 py-3 rounded-xl bg-accent-coral/10 border border-accent-coral/30 text-[12.5px] text-accent-coral">
                <div class="font-semibold mb-1">{{ __('Couldn’t connect the SMS number:') }}</div>
                <ul class="list-disc pl-5 space-y-0.5">
                    @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6">

            <!-- =============== LEFT SIDEBAR =============== -->
            <aside class="space-y-3">
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                    <div class="w-12 h-12 rounded-xl mb-3 grid place-items-center bg-wa-bubble">
                        <svg viewBox="0 0 24 24" class="w-7 h-7 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16v10H10l-4 3.5V16H4z" /></svg>
                    </div>
                    <div class="font-serif text-[18px] leading-tight">{{ __('SMS') }}</div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mt-1">{{ __('Twilio · MSG91') }}</div>
                    <div class="mt-3 inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-[10px] font-mono {{ $activeCount ? 'bg-wa-mint text-wa-deep border border-wa-green/40' : 'bg-paper-50 border border-paper-200 text-ink-700' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $activeCount ? 'bg-wa-green' : 'bg-paper-200' }}"></span>
                        {{ $activeCount ? trans_choice('{1}:count number active|[2,*]:count numbers active', $activeCount, ['count' => $activeCount]) : __('Not connected') }}
                    </div>
                </div>

                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-2 pb-1.5">{{ __('How it works') }}</div>
                    <ol class="px-1 space-y-0.5">
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-wa-deep text-paper-0 shrink-0 mt-0.5">1</span>
                            {{ __('Connect a Twilio or MSG91 number (reuses your Twilio keys)') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">2</span>
                            {{ __('Paste the two webhook URLs into the provider') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">3</span>
                            {{ __('Inbound texts land in the same team inbox as WhatsApp') }}
                        </li>
                        <li class="flex items-start gap-2 px-3 py-2 rounded-lg text-[12.5px] text-ink-700">
                            <span class="w-5 h-5 rounded-full grid place-items-center text-[10px] font-mono bg-paper-100 text-ink-500 shrink-0 mt-0.5">4</span>
                            {{ __('Campaigns gain an SMS sender — billed to your plan’s SMS quota, never the WhatsApp wallet') }}
                        </li>
                    </ol>

                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 px-3 pt-3 pb-1.5">{{ __('Related') }}</div>
                    @foreach ([['/team-inbox', __('Unified Inbox')], ['/broadcasts', __('Campaigns')], ['/devices', __('Channels')]] as [$href, $label])
                        <a href="{{ url($href) }}" class="flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] text-ink-700 hover:bg-paper-50">
                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 4l4 4-4 4" /></svg>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </aside>

            <!-- =============== MAIN =============== -->
            <div class="space-y-5 min-w-0">

                {{-- Webhook URLs — the ONE thing to remember. --}}
                <div class="bg-paper-0 border border-wa-green/40 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-paper-200 flex items-center gap-2">
                        <svg viewBox="0 0 16 16" class="w-4 h-4 text-wa-deep" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M8 2v6M8 11v.5" /><circle cx="8" cy="8" r="6.5" /></svg>
                        <h2 class="font-serif text-[16px]">{{ __('Remember: paste these into your provider') }}</h2>
                    </div>
                    <div class="p-5 grid md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-[11.5px] font-semibold text-ink-700 mb-1">{{ __('Inbound / Messaging webhook') }}</div>
                            <div class="flex items-stretch gap-2">
                                <code class="flex-1 px-3 py-2 rounded-lg bg-paper-50 border border-paper-200 text-[11.5px] font-mono text-ink-800 break-all">{{ $inboundUrl }}</code>
                                <button type="button" class="px-3 rounded-lg border border-paper-200 text-[11px] font-semibold hover:bg-paper-50 shrink-0" onclick="navigator.clipboard&&navigator.clipboard.writeText('{{ $inboundUrl }}');this.textContent='{{ __('Copied') }}';">{{ __('Copy') }}</button>
                            </div>
                            <div class="text-[10.5px] text-ink-500 mt-1">{{ __('Twilio: number → Messaging → “A message comes in” (HTTP POST).') }}</div>
                        </div>
                        <div>
                            <div class="text-[11.5px] font-semibold text-ink-700 mb-1">{{ __('Delivery-status callback') }}</div>
                            <div class="flex items-stretch gap-2">
                                <code class="flex-1 px-3 py-2 rounded-lg bg-paper-50 border border-paper-200 text-[11.5px] font-mono text-ink-800 break-all">{{ $statusUrl }}</code>
                                <button type="button" class="px-3 rounded-lg border border-paper-200 text-[11px] font-semibold hover:bg-paper-50 shrink-0" onclick="navigator.clipboard&&navigator.clipboard.writeText('{{ $statusUrl }}');this.textContent='{{ __('Copied') }}';">{{ __('Copy') }}</button>
                            </div>
                            <div class="text-[10.5px] text-ink-500 mt-1">{{ __('Twilio: same number → “Status callback URL”.') }}</div>
                        </div>
                    </div>
                </div>

                {{-- Connected numbers (multiple). --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200 flex items-center justify-between gap-3">
                        <h2 class="font-serif text-[18px] leading-tight">{{ __('Connected numbers') }}</h2>
                        <span class="text-[11px] font-mono text-ink-500">{{ $senders->count() }} {{ __('total') }}</span>
                    </div>
                    @if ($senders->isEmpty())
                        <div class="px-5 py-8 text-center">
                            <div class="text-[13px] text-ink-600">{{ __('No SMS number connected yet.') }}</div>
                            <a href="#sms-connect" class="inline-block mt-3 px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold">{{ __('Connect your first number') }}</a>
                        </div>
                    @else
                        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
                            @foreach ($senders as $s)
                                @php
                                    $meta = is_array($s->meta_json) ? $s->meta_json : [];
                                    $prov = strtolower($meta['sms_provider'] ?? 'twilio');
                                    $connected = $s->status === \App\Models\WaProviderConfig::STATUS_CONNECTED;
                                    $isMsg91 = $prov === 'msg91';
                                @endphp
                                <div class="border border-paper-200 rounded-xl p-4 flex flex-col gap-3">
                                    <div class="flex items-start gap-3">
                                        <span class="w-9 h-9 rounded-xl grid place-items-center shrink-0 {{ $isMsg91 ? 'bg-accent-amber/20 text-accent-amber' : 'bg-wa-mint text-wa-deep' }}">
                                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4.5h12v7H8l-3 2.5V11.5H2z" /></svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-[14px] font-semibold text-ink-900 font-mono truncate">{{ $s->phone_number }}</div>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <span class="text-[10px] font-mono uppercase tracking-wide px-1.5 py-0.5 rounded {{ $isMsg91 ? 'bg-accent-amber/15 text-accent-amber' : 'bg-wa-mint text-wa-deep' }}">{{ $isMsg91 ? 'MSG91' : 'Twilio' }}</span>
                                                <span class="inline-flex items-center gap-1 text-[10.5px] {{ $connected ? 'text-wa-deep' : 'text-ink-400' }}">
                                                    <span class="w-1.5 h-1.5 rounded-full {{ $connected ? 'bg-wa-green' : 'bg-ink-300' }}"></span>{{ $connected ? __('Active') : __('Inactive') }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <dl class="text-[11px] text-ink-600 space-y-1">
                                        <div class="flex justify-between gap-2"><dt class="text-ink-500">{{ __('Rate / segment') }}</dt><dd class="font-mono">{{ ($meta['rate_per_segment'] ?? null) !== null ? ($meta['rate_per_segment'] . ' ' . ($meta['currency'] ?? 'USD')) : __('—') }}</dd></div>
                                        @if ($isMsg91)
                                            <div class="flex justify-between gap-2"><dt class="text-ink-500">{{ __('DLT sender') }}</dt><dd class="font-mono">{{ $meta['sender_id'] ?? '—' }}</dd></div>
                                            <div class="flex justify-between gap-2"><dt class="text-ink-500">{{ __('DLT template') }}</dt><dd class="font-mono truncate max-w-30">{{ $meta['dlt_template_id'] ?? '—' }}</dd></div>
                                        @endif
                                    </dl>
                                    <div class="flex items-center gap-2 pt-2 border-t border-paper-100 mt-auto">
                                        <form method="POST" action="{{ url('/sms/' . $s->id . '/toggle') }}">@csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-full border border-paper-200 text-[11px] font-semibold hover:bg-paper-50">{{ $connected ? __('Deactivate') : __('Activate') }}</button>
                                        </form>
                                        <form method="POST" action="{{ url('/sms/' . $s->id) }}" onsubmit="return confirm('{{ __('Remove this SMS number?') }}');">@csrf @method('DELETE')
                                            <button type="submit" class="px-3 py-1.5 rounded-full border border-accent-coral/30 text-accent-coral text-[11px] font-semibold hover:bg-accent-coral/10">{{ __('Remove') }}</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Number lookup. --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <h2 class="font-serif text-[18px] leading-tight">{{ __('Check a number before you text it') }}</h2>
                        <p class="text-[11.5px] text-ink-600 mt-0.5">{{ __('SMS is charged on submission — a landline or dead number costs the same as one that arrives. Twilio Lookup tells you which is which.') }}</p>
                    </div>
                    <div class="p-5 flex items-center gap-2 flex-wrap" data-sms-lookup data-lookup-url="{{ url('/sms/lookup') }}">
                        <input type="text" data-lookup-input placeholder="+1 555 123 4567" class="px-3 py-2 rounded-xl border border-paper-200 bg-white text-[13px] font-mono focus:outline-none focus:border-wa-deep focus:ring-4 focus:ring-wa-deep/10 w-full sm:w-72">
                        <button type="button" data-lookup-btn class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Check number') }}</button>
                        <span data-lookup-result class="text-[12px] text-ink-600"></span>
                    </div>
                </div>

                {{-- Connect a new number. --}}
                <div id="sms-connect" class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <h2 class="font-serif text-[18px] leading-tight">{{ __('Connect a number') }}</h2>
                        <p class="text-[11.5px] text-ink-600 mt-1">
                            {{ __('Add as many as you need — several Twilio numbers and MSG91 senders can run at once.') }}
                            @if ($hasTwilio) {{ __('You already have Twilio connected — leave the Account SID / Auth Token blank to reuse those keys.') }} @endif
                        </p>
                    </div>
                    <div class="p-5">
                        @include('user.devices._sms_form', ['smsReturnTo' => '/sms'])
                    </div>
                </div>

                {{-- Setup guide. --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <h2 class="font-serif text-[18px] leading-tight">{{ __('Setup guide (what to remember)') }}</h2>
                    </div>
                    <div class="divide-y divide-paper-200">
                        <details class="px-5 py-3.5" open>
                            <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('Twilio (global)') }}</summary>
                            <ol class="list-decimal pl-5 mt-2 space-y-1.5 text-[12px] text-ink-600">
                                <li>{{ __('Copy your Account SID (starts “AC”) + Auth Token from the Twilio Console. An API Key (“SK…”) will NOT work.') }}</li>
                                <li>{{ __('Buy an SMS-capable number (or use the trial number), then paste number + keys in the form above.') }}</li>
                                <li>{{ __('Number → Messaging → “A message comes in”: HTTP POST to the Inbound webhook above.') }}</li>
                                <li>{{ __('Set the “Status callback URL” to the Delivery-status URL above.') }}</li>
                                <li>{{ __('Trial accounts only text verified numbers and refuse custom text — add billing to send freely.') }}</li>
                            </ol>
                        </details>
                        <details class="px-5 py-3.5">
                            <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('MSG91 (India / DLT)') }}</summary>
                            <ol class="list-decimal pl-5 mt-2 space-y-1.5 text-[12px] text-ink-600">
                                <li>{{ __('Indian carriers filter foreign long-codes — use MSG91 with DLT for reliable India delivery.') }}</li>
                                <li>{{ __('Paste your MSG91 Auth Key in the Auth Token / Auth Key field.') }}</li>
                                <li>{{ __('Register a 6-letter DLT Sender ID (e.g. :example) + your DLT template on the DLT portal, then paste both in the MSG91 options.', ['example' => brand_token(6)]) }}</li>
                            </ol>
                        </details>
                        <details class="px-5 py-3.5">
                            <summary class="text-[13px] font-semibold text-ink-800 cursor-pointer">{{ __('Segments, cost & billing') }}</summary>
                            <ul class="list-disc pl-5 mt-2 space-y-1.5 text-[12px] text-ink-600">
                                <li>{{ __('One SMS = 160 characters (GSM-7). A single emoji / non-Latin character drops the limit to 70 (UCS-2) and can triple the cost.') }}</li>
                                <li>{{ __('The rate per segment is used for your SMS spend reporting only.') }}</li>
                                <li>{{ __('SMS is metered by your plan’s monthly SMS quota — NEVER billed to the WhatsApp wallet.') }}</li>
                                <li>{{ __('An alphanumeric sender ID is one-way: customers get no number to reply to, so inbound never arrives.') }}</li>
                            </ul>
                        </details>
                    </div>
                </div>

            </div>
        </div>
    </main>
</x-layouts.user>
