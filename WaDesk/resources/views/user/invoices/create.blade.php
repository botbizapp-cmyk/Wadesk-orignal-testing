<x-layouts.user :title="__('New invoice')" nav-key="more" page="invoices-create">
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Invoices') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('New') }} <span class="italic text-wa-deep">{{ __('invoice') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Build an invoice from scratch — no order needed. It gets a real number, a PDF, and a shareable link.') }}</p>
            </div>
            <a href="{{ route('user.invoices.index') }}" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100 shrink-0">{{ __('Back to invoices') }}</a>
        </div>

        @if ($errors->any())
            <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">
                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif

        <form method="POST" action="{{ route('user.invoices.manual') }}" id="inv-form" class="space-y-6">
            @csrf

            {{-- Buyer + meta --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 space-y-4">
                <div class="text-[13px] font-semibold text-ink-900">{{ __('Bill to') }}</div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Customer name') }}</span>
                        <input name="buyer_name" value="{{ old('buyer_name') }}" required maxlength="191" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Email') }}</span>
                        <input name="buyer_email" type="email" value="{{ old('buyer_email') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Phone') }}</span>
                        <input name="buyer_phone" value="{{ old('buyer_phone') }}" maxlength="40" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"></label>
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Document type') }}</span>
                        <select name="doc_type" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="tax_invoice">{{ __('Tax invoice') }}</option>
                            <option value="receipt">{{ __('Receipt') }}</option>
                            <option value="proforma">{{ __('Proforma') }}</option>
                        </select></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Currency') }}</span>
                        <input name="currency" id="inv-currency" value="{{ old('currency', $settings->currency ?? 'USD') }}" maxlength="3" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] font-mono uppercase focus:outline-none focus:border-wa-deep"></label>
                </div>
            </div>

            {{-- Line items --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <div class="text-[13px] font-semibold text-ink-900">{{ __('Items') }}</div>
                    <button type="button" id="inv-add-row" class="text-[11px] text-wa-deep font-semibold hover:underline">+ {{ __('Add item') }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]" id="inv-items">
                        <thead>
                            <tr class="text-ink-500 text-[10.5px] uppercase tracking-wide">
                                <th class="text-left font-semibold pb-1">{{ __('Description') }}</th>
                                <th class="text-right font-semibold pb-1 w-20">{{ __('Qty') }}</th>
                                <th class="text-right font-semibold pb-1 w-28">{{ __('Unit price') }}</th>
                                <th class="text-right font-semibold pb-1 w-20">{{ __('Tax %') }}</th>
                                <th class="text-right font-semibold pb-1 w-24">{{ __('Amount') }}</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody id="inv-rows">
                            {{-- rows injected by JS; one starter row rendered on load --}}
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Totals + adjustments --}}
            <div class="grid sm:grid-cols-2 gap-6 items-start">
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 space-y-3">
                    <div class="text-[13px] font-semibold text-ink-900">{{ __('Adjustments') }}</div>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Discount') }}</span>
                        <input name="discount" id="inv-discount" type="number" step="0.01" min="0" value="{{ old('discount', 0) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Shipping') }}</span>
                        <input name="shipping" id="inv-shipping" type="number" step="0.01" min="0" value="{{ old('shipping', 0) }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Internal note') }}</span>
                        <textarea name="note" rows="2" maxlength="2000" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">{{ old('note') }}</textarea></label>
                </div>
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 space-y-2">
                    <div class="text-[13px] font-semibold text-ink-900 mb-1">{{ __('Summary') }}</div>
                    <div class="flex justify-between text-[12.5px]"><span class="text-ink-600">{{ __('Subtotal') }}</span><span class="font-mono" id="sum-subtotal">0.00</span></div>
                    <div class="flex justify-between text-[12.5px]"><span class="text-ink-600">{{ __('Tax') }}</span><span class="font-mono" id="sum-tax">0.00</span></div>
                    <div class="flex justify-between text-[12.5px]"><span class="text-ink-600">{{ __('Discount') }}</span><span class="font-mono" id="sum-discount">0.00</span></div>
                    <div class="flex justify-between text-[12.5px]"><span class="text-ink-600">{{ __('Shipping') }}</span><span class="font-mono" id="sum-shipping">0.00</span></div>
                    <div class="border-t border-paper-200 pt-2 mt-1 flex justify-between text-[15px] font-semibold"><span>{{ __('Total') }}</span><span class="font-mono text-wa-deep" id="sum-total">0.00</span></div>
                </div>
            </div>

            {{-- Optional CRM link --}}
            <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5 space-y-3">
                <div class="text-[13px] font-semibold text-ink-900">{{ __('Link to CRM') }} <span class="text-[11px] font-normal text-ink-400">{{ __('(optional — so revenue rolls up)') }}</span></div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Contact') }}</span>
                        <select name="contact_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— none —') }}</option>
                            @foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name ?: ('#' . $c->id) }}</option>@endforeach
                        </select></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Company') }}</span>
                        <select name="company_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— none —') }}</option>
                            @foreach ($companies as $co)<option value="{{ $co->id }}">{{ $co->name ?: ('#' . $co->id) }}</option>@endforeach
                        </select></label>
                    <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Deal') }}</span>
                        <select name="deal_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                            <option value="">{{ __('— none —') }}</option>
                            @foreach ($deals as $d)<option value="{{ $d->id }}">{{ $d->title ?: ('#' . $d->id) }}</option>@endforeach
                        </select></label>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('user.invoices.index') }}" class="px-5 py-2.5 rounded-full border border-paper-200 text-[12.5px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Cancel') }}</a>
                <button type="submit" class="px-5 py-2.5 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Create invoice') }}</button>
            </div>
        </form>
    </main>
</x-layouts.user>
