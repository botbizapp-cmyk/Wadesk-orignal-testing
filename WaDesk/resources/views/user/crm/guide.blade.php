@php
    // Every CRM surface, grouped. Each: icon path, title, url, one-line what, how-to steps.
    $sections = [
        [
            'group' => __('Sell — quote to cash'),
            'blurb' => __('The path a sale takes: quote a price, get it accepted, turn it into a paid invoice.'),
            'items' => [
                [
                    'title' => __('Estimates'), 'url' => url('/estimates'), 'tint' => ['#FEF3C7', '#B45309'],
                    'icon' => '<rect x="2.5" y="2" width="11" height="12" rx="1.5"/><path d="M5 5.5h6M5 8h6M8.5 10.5h2.5"/>',
                    'what' => __('A quick priced quote with line items and tax.'),
                    'steps' => [
                        __('Click "+ New estimate", add buyer + line items (total adds up live).'),
                        __('Hit the green "Send on WhatsApp" to deliver the link to the buyer.'),
                        __('When they accept, click "→ Invoice" to make a real invoice.'),
                    ],
                ],
                [
                    'title' => __('Proposals'), 'url' => url('/proposals'), 'tint' => ['#EDE9FE', '#6D28D9'],
                    'icon' => '<rect x="3" y="1.5" width="10" height="13" rx="1.5"/><path d="M5.5 5h5M5.5 8h5M5.5 11h3"/>',
                    'what' => __('A fuller pitch — same flow as estimates, for bigger deals.'),
                    'steps' => [
                        __('Create it, add scope as line items, set a "valid until" date.'),
                        __('Send the link; the customer opens it and taps Accept or Decline.'),
                        __('You get notified, then convert the winner to an invoice.'),
                    ],
                ],
                [
                    'title' => __('Invoices'), 'url' => url('/invoices'), 'tint' => ['#DCFCE7', '#0b7a4b'],
                    'icon' => '<rect x="3" y="2" width="10" height="12" rx="1.5"/><path d="M6 6h4M6 9h4M6 11.5h2.5"/>',
                    'what' => __('The real bill — numbered, PDF, shareable public link.'),
                    'steps' => [
                        __('Made in one click from an accepted quote — or "+ New invoice" manually.'),
                        __('Share the /i/ link or send it; the buyer sees a proper invoice.'),
                        __('Record what they pay in Payments to clear the balance.'),
                    ],
                ],
                [
                    'title' => __('Payments'), 'url' => url('/payments'), 'tint' => ['#E0F2FE', '#0369A1'],
                    'icon' => '<rect x="2" y="4" width="12" height="8" rx="1.5"/><path d="M2 7h12"/>',
                    'what' => __('Record money received and see what is still outstanding.'),
                    'steps' => [
                        __('Click "Record payment", pick the invoice, enter the amount.'),
                        __('The invoice flips to Paid automatically when the balance clears.'),
                        __('The aging panel shows who owes you and how overdue they are.'),
                    ],
                ],
            ],
        ],
        [
            'group' => __('Deliver — after they pay'),
            'blurb' => __('Track the work you promised and never miss a deadline.'),
            'items' => [
                [
                    'title' => __('Projects'), 'url' => url('/projects'), 'tint' => ['#E0F2FE', '#0369A1'],
                    'icon' => '<path d="M2 4.5h5l1.2 1.5H14v6.5a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V4.5Z"/>',
                    'what' => __('A board of the work you deliver — In progress / Overdue / Completed.'),
                    'steps' => [
                        __('Click "+ New project", link a company, set a due date.'),
                        __('Drag the progress slider on the card as work gets done.'),
                        __('At 100% it moves itself to Completed; late ones go to Overdue.'),
                    ],
                ],
                [
                    'title' => __('Tasks'), 'url' => url('/tasks'), 'tint' => ['#FCE7F3', '#BE185D'],
                    'icon' => '<rect x="2" y="2" width="12" height="12" rx="2.5"/><path d="M5.5 8l1.8 1.8L11 5.5"/>',
                    'what' => __('Your to-dos — assign, prioritize, get reminded.'),
                    'steps' => [
                        __('Add a task with a due date and who it is for.'),
                        __('It shows in Overdue / Today / Upcoming buckets.'),
                        __('You get an in-app + WhatsApp reminder near the due time.'),
                    ],
                ],
                [
                    'title' => __('Calendar'), 'url' => url('/calendar'), 'tint' => ['#DCFCE7', '#0b7a4b'],
                    'icon' => '<rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6h12M5.5 1.5v3M10.5 1.5v3"/>',
                    'what' => __('One month grid of every deadline you have.'),
                    'steps' => [
                        __('Opens on this month — coloured bars are your dated items.'),
                        __('Tasks, project due dates, deal closes and quote expiries, all mixed.'),
                        __('Click any bar to jump to that item; use ‹ › to change month.'),
                    ],
                ],
            ],
        ],
        [
            'group' => __('Organize + understand'),
            'blurb' => __('Who your customers are, your pipeline, and how the business is doing.'),
            'items' => [
                [
                    'title' => __('Contacts'), 'url' => url('/contacts'), 'tint' => ['#F1F5F9', '#334155'],
                    'icon' => '<circle cx="8" cy="5.5" r="2.5"/><path d="M3.5 13a4.5 4.5 0 0 1 9 0"/>',
                    'what' => __('Every person — auto-captured from real WhatsApp chats.'),
                    'steps' => [__('Contacts appear on their own when people message you.'), __('Add tags, status and company to organize them.'), __('Open a contact to see their full history.')],
                ],
                [
                    'title' => __('Companies'), 'url' => url('/companies'), 'tint' => ['#EDE9FE', '#6D28D9'],
                    'icon' => '<rect x="2.5" y="3" width="7" height="11" rx="1"/><path d="M9.5 7H13v7H9.5M5 6h2M5 9h2"/>',
                    'what' => __('Group contacts + deals under the business they belong to.'),
                    'steps' => [__('Create a company, then link its contacts and deals.'), __('The company page rolls up its people and revenue.'), __('Generate a client brief straight from the company.')],
                ],
                [
                    'title' => __('Deals'), 'url' => url('/deals'), 'tint' => ['#DCFCE7', '#0b7a4b'],
                    'icon' => '<path d="M2 8l6-5 6 5-6 5-6-5Z"/>',
                    'what' => __('Your sales pipeline — drag opportunities stage to stage.'),
                    'steps' => [__('Create a deal with a value and a stage.'), __('Drag its card across the board as it progresses.'), __('Mark Won/Lost; it feeds your revenue reports.')],
                ],
                [
                    'title' => __('CRM dashboard'), 'url' => url('/crm'), 'tint' => ['#E0F2FE', '#0369A1'],
                    'icon' => '<rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/>',
                    'what' => __('Deals, invoices, payments and tasks — one screen.'),
                    'steps' => [__('Opens with your key numbers for the month.'), __('Jump to the Revenue report for collected / outstanding / tax.'), __('Export any report to CSV or PDF.')],
                ],
            ],
        ],
        [
            'group' => __('The AI Copilot'),
            'blurb' => __('Run everything above just by chatting — on the dashboard or from your own WhatsApp.'),
            'items' => [
                [
                    'title' => __('AI CRM Copilot'), 'url' => url('/ai-crm'), 'tint' => ['#F3E8FF', '#7C3AED'],
                    'icon' => '<path d="M8 2v3M8 11v3M2 8h3M11 8h3"/><circle cx="8" cy="8" r="2.5"/>',
                    'what' => __('Type in plain English; the AI does it and shows you before acting.'),
                    'steps' => [
                        __('Try: "create an estimate for Acme, 2h consulting at 80".'),
                        __('Try: "send Priya the menu photography estimate".'),
                        __('Anything that changes data waits for your OK first.'),
                    ],
                ],
            ],
        ],
    ];
@endphp
<x-layouts.user :title="__('AI CRM — How to use')" nav-key="more" page="user-crm-guide">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-7">
        {{-- Hero --}}
        <div class="bg-gradient-to-br from-wa-deep to-wa-teal text-paper-0 rounded-[16px] p-7 sm:p-9">
            <div class="font-mono text-[10px] uppercase tracking-[0.2em] opacity-85 mb-3">{{ __('AI CRM') }}</div>
            <h1 class="font-serif text-[30px] sm:text-[40px] leading-[1.05] max-w-2xl">{{ __('Everything in your CRM, and how to use it') }}</h1>
            <p class="mt-3 text-[13.5px] opacity-90 max-w-2xl leading-relaxed">{{ __('The whole flow: quote a price → customer accepts → it becomes an invoice → you get paid → you track the work → you see every deadline. Below is each page, what it does, and the 3 steps to use it.') }}</p>
            <div class="mt-5 flex items-center gap-3 flex-wrap">
                <a href="{{ url('/estimates') }}" class="px-4 py-2 rounded-full bg-paper-0 text-wa-deep text-[12.5px] font-semibold hover:bg-paper-100">{{ __('Start with an estimate') }}</a>
                <a href="{{ url('/ai-crm') }}" class="px-4 py-2 rounded-full border border-paper-0/40 text-paper-0 text-[12.5px] font-semibold hover:bg-paper-0/10">{{ __('Or just ask the AI') }}</a>
            </div>
        </div>

        {{-- The flow strip --}}
        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
            <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-3">{{ __('The flow') }}</div>
            <div class="flex items-center gap-2 flex-wrap text-[12.5px] font-semibold">
                @foreach ([__('Estimate / Proposal'), __('Customer accepts'), __('Invoice'), __('Payment'), __('Project'), __('Calendar')] as $i => $step)
                    @if ($i > 0)<svg viewBox="0 0 16 16" class="w-3.5 h-3.5 text-ink-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3l5 5-5 5"/></svg>@endif
                    <span class="px-3 py-1.5 rounded-full bg-paper-100 text-ink-700">{{ $step }}</span>
                @endforeach
            </div>
        </div>

        {{-- A worked example, start to finish --}}
        @php
            $brand = function_exists('brand_name') ? brand_name() : config('app.name');
            $example = [
                ['n' => 1, 'page' => __('Estimates'), 'title' => __('You quote a price'),
                 'body' => __('A customer (say Priya) asks on WhatsApp for menu photos. You open <b>Estimates → + New estimate</b>, add her name + number, and two lines: "Photo shoot ×1 = 12,000" and "Editing ×1 = 3,000". The total shows <b>15,000</b>. Click <b>Create</b> — it becomes <b>EST-0003</b>.')],
                ['n' => 2, 'page' => __('Send'), 'title' => __('You send it — from your own WhatsApp'),
                 'body' => __('On that row you click the green <b>Send</b>. :brand sends Priya a WhatsApp from your connected number with a link. (Or in the AI chat you type "send Priya the menu photography estimate".)', ['brand' => $brand])],
                ['n' => 3, 'page' => __('Accept'), 'title' => __('Priya accepts — by herself'),
                 'body' => __('Priya taps the link, sees the price, and taps <b>✓ Accept</b>. You get a notification. The estimate row now shows <b>Accepted</b> — no chasing, no phone call.')],
                ['n' => 4, 'page' => __('Invoice'), 'title' => __('It becomes a real invoice'),
                 'body' => __('You click <b>→ Invoice</b> on the accepted estimate. :brand makes a proper numbered invoice (with PDF) from the same lines — you retype nothing. You send that to collect the money.', ['brand' => $brand])],
                ['n' => 5, 'page' => __('Payment'), 'title' => __('You record the payment'),
                 'body' => __('When Priya pays, open <b>Payments → Record payment</b>, pick the invoice, enter 15,000. The invoice flips to <b>Paid</b> automatically.')],
                ['n' => 6, 'page' => __('Project'), 'title' => __('You track the actual work'),
                 'body' => __('Now you have to shoot + edit. <b>Projects → + New project</b> "Priya photo shoot", due in a week. Drag the progress slider as you go; at 100% it marks <b>Completed</b>.')],
                ['n' => 7, 'page' => __('Calendar'), 'title' => __('Everything shows on one calendar'),
                 'body' => __('The project deadline, any tasks, and the quote\'s expiry all appear on <b>Calendar</b> — so your whole week is one screen.')],
            ];
        @endphp
        <section class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
            <div class="px-5 sm:px-6 py-4 border-b border-paper-200 bg-paper-50">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500">{{ __('A real example') }}</div>
                <h2 class="font-serif text-[22px] leading-tight mt-1">{{ __('From "how much?" to paid — one customer, seven steps') }}</h2>
            </div>
            <ol class="divide-y divide-paper-100">
                @foreach ($example as $s)
                    <li class="flex gap-4 px-5 sm:px-6 py-4">
                        <div class="shrink-0 flex flex-col items-center">
                            <span class="w-8 h-8 rounded-full bg-wa-deep text-paper-0 grid place-items-center text-[13px] font-semibold">{{ $s['n'] }}</span>
                            @if (! $loop->last)<span class="w-px flex-1 bg-paper-200 mt-1"></span>@endif
                        </div>
                        <div class="min-w-0 pb-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[15px] font-semibold text-ink-900">{{ $s['title'] }}</span>
                                <span class="px-2 py-0.5 rounded-full bg-wa-mint text-wa-deep text-[10px] font-mono">{{ $s['page'] }}</span>
                            </div>
                            <p class="text-[13px] text-ink-600 leading-relaxed mt-1">{!! $s['body'] !!}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <div class="px-5 sm:px-6 py-3.5 bg-wa-mint/40 border-t border-wa-green/30 text-[12.5px] text-wa-deep">
                {{ __('That is the entire system. Every page below is one part of this same loop — open any of them to try it.') }}
            </div>
        </section>

        {{-- Sections --}}
        @foreach ($sections as $sec)
            <section class="space-y-4">
                <div>
                    <h2 class="font-serif text-[22px] leading-tight">{{ $sec['group'] }}</h2>
                    <p class="text-[12.5px] text-ink-500 mt-1 max-w-2xl">{{ $sec['blurb'] }}</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    @foreach ($sec['items'] as $it)
                        <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 flex flex-col">
                            <div class="flex items-start justify-between gap-3">
                                <span class="w-11 h-11 rounded-xl grid place-items-center" style="background: {{ $it['tint'][0] }}; color: {{ $it['tint'][1] }}">
                                    <svg viewBox="0 0 16 16" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.3">{!! $it['icon'] !!}</svg>
                                </span>
                                <a href="{{ $it['url'] }}" class="px-3 py-1.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[11.5px] font-semibold shrink-0">{{ __('Open') }}</a>
                            </div>
                            <h3 class="mt-4 text-[16px] font-semibold leading-tight">{{ $it['title'] }}</h3>
                            <p class="mt-1 text-[12px] text-ink-500 leading-snug">{{ $it['what'] }}</p>
                            <ol class="mt-3 pt-3 border-t border-paper-200 space-y-2 flex-1">
                                @foreach ($it['steps'] as $n => $step)
                                    <li class="flex gap-2.5 text-[12px] text-ink-700 leading-snug">
                                        <span class="w-4.5 h-4.5 shrink-0 rounded-full bg-paper-100 text-ink-600 text-[10px] font-mono grid place-items-center" style="width:18px;height:18px">{{ $n + 1 }}</span>
                                        <span>{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="text-center text-[12px] text-ink-400 pt-2">{{ __('Tip: every page here is also in the top menu and on the More page.') }}</div>
    </main>
</x-layouts.user>
