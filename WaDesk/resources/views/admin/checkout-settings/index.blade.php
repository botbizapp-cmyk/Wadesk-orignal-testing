<x-layouts.admin :title="__('Admin · Billing settings')" admin-key="checkout-settings">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M4 3l3 3-3 3" />
            </svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Billing settings') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-6 lg:px-7 py-7 space-y-5">

        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">
                    {{ __('Admin · Billing & plans · Settings') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[30px] sm:text-[40px] leading-[1.0]">{{ __('Billing') }}
                    <span class="italic text-wa-deep">{{ __('settings') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-3xl">
                    {{ __('Everything the pricing & checkout pages read — tax, refund window, yearly discount, auto-renew, your invoice identity, and the country list. Changes take effect on the next page render; no cache clear needed.') }}
                </p>
            </div>
            <a href="{{ url('/account/plans') }}" target="_blank"
                class="shrink-0 px-4 py-2 border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-semibold">{{ __('Preview plans page') }}</a>
        </div>

        <x-admin.flash />

        <form method="POST" action="{{ route('admin.checkout-settings.update') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            {{-- Tax --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h2 class="font-serif text-[22px]">{{ __('Tax') }}</h2>
                        <p class="text-[12px] text-ink-500 mt-0.5">
                            {{ __('Applied to every checkout subtotal. Turn off if your displayed prices are tax-inclusive.') }}
                        </p>
                    </div>
                    <label class="toggle"><input type="hidden" name="tax_enabled" value="0"><input type="checkbox"
                            name="tax_enabled" value="1" @checked($taxEnabled)><span
                            class="track"></span><span class="thumb"></span></label>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="text-[12px] text-ink-700">{{ __('Rate (%)') }} <input type="number" name="tax_rate"
                            min="0" max="100" value="{{ $taxRate }}"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                    </label>
                    <label class="text-[12px] text-ink-700">{{ __('Label') }} <input type="text" name="tax_label"
                            maxlength="32" value="{{ $taxLabel }}" placeholder="{{ __('GST / VAT / Sales tax') }}"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                    </label>
                </div>
            </section>

            {{-- Company / billing identity --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <h2 class="font-serif text-[22px]">{{ __('Company & billing identity') }}</h2>
                <p class="text-[12px] text-ink-500 mt-0.5 mb-4">
                    {{ __('Your legal entity details. These print on every invoice — the "bill from" block, the tax number, and the footer.') }}
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label class="text-[12px] text-ink-700">{{ __('Legal company name') }}
                        <input type="text" name="company_name" maxlength="160" value="{{ $companyName }}"
                            placeholder="{{ brand_name() }} Technologies Pvt. Ltd."
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px]" />
                    </label>
                    <label class="text-[12px] text-ink-700">{{ __('Tax number (GSTIN / VAT)') }}
                        <input type="text" name="company_tax_id" maxlength="64" value="{{ $companyTaxId }}"
                            placeholder="27AABCW1234F1Z9"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                    </label>
                    <label class="text-[12px] text-ink-700 sm:col-span-2">{{ __('Registered address') }}
                        <textarea name="company_address" rows="3" placeholder="21 Floor, Birla Centurion, Worli&#10;Mumbai 400030 · India"
                            class="mt-1 w-full px-3 py-2 border border-paper-200 rounded-lg bg-white text-[13px]">{{ $companyAddress }}</textarea>
                    </label>
                    <label class="text-[12px] text-ink-700">{{ __('Registration / CIN no.') }}
                        <input type="text" name="company_reg_no" maxlength="64" value="{{ $companyRegNo }}"
                            placeholder="U72900MH2024PTC456789"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                    </label>
                    <label class="text-[12px] text-ink-700">{{ __('Billing email') }}
                        <input type="email" name="company_email" maxlength="160" value="{{ $companyEmail }}"
                            placeholder="billing@example.com"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px]" />
                    </label>
                    <label class="text-[12px] text-ink-700">{{ __('Support phone') }}
                        <input type="text" name="company_phone" maxlength="40" value="{{ $companyPhone }}"
                            placeholder="+91 22 6612 0099"
                            class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px]" />
                    </label>
                </div>

                {{-- Invoice logo — printed at the top of every platform invoice /
                     receipt (replaces the wordmark + generic icon). --}}
                <div class="mt-5 pt-5 border-t border-paper-200">
                    <div class="text-[12px] font-semibold text-ink-800">{{ __('Invoice logo') }}</div>
                    <p class="text-[12px] text-ink-500 mt-0.5 mb-3">
                        {{ __('Shown at the top of every invoice and receipt. PNG, JPG, SVG or WebP, up to 1 MB. Leave empty to use your site logo.') }}
                    </p>
                    <div class="flex items-center gap-4 flex-wrap">
                        <span class="w-28 h-16 rounded-lg border border-paper-200 bg-paper-50 grid place-items-center overflow-hidden shrink-0">
                            @if ($invoiceLogoUrl)
                                <img src="{{ $invoiceLogoUrl }}" alt="{{ __('Invoice logo') }}" class="max-h-full max-w-full object-contain" />
                            @else
                                <span class="text-[10px] text-ink-400 font-mono">{{ __('No logo') }}</span>
                            @endif
                        </span>
                        <div class="flex-1 min-w-[220px]">
                            <input type="file" name="company_logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                class="block w-full text-[12px] text-ink-700 file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-wa-deep file:text-paper-0 file:text-[12px] file:font-semibold hover:file:bg-wa-teal file:cursor-pointer" />
                            @if ($hasInvoiceLogo)
                                <label class="mt-2 inline-flex items-center gap-1.5 text-[12px] text-ink-600 cursor-pointer">
                                    <input type="checkbox" name="remove_logo" value="1" class="rounded border-paper-300" />
                                    {{ __('Remove current logo (fall back to site logo)') }}
                                </label>
                            @else
                                <p class="mt-1.5 text-[11px] text-ink-400">{{ __('Currently using the site brand logo as a fallback.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Refund guarantee --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h2 class="font-serif text-[22px]">{{ __('Money-back guarantee') }}</h2>
                        <p class="text-[12px] text-ink-500 mt-0.5">
                            {{ __('When enabled, the green ribbon on the right of /checkout shows the guarantee window. Turn off to hide it everywhere.') }}
                        </p>
                    </div>
                    <label class="toggle"><input type="hidden" name="refund_enabled" value="0"><input
                            type="checkbox" name="refund_enabled" value="1" @checked($refundEnabled)><span
                            class="track"></span><span class="thumb"></span></label>
                </div>
                <label class="text-[12px] text-ink-700 block max-w-[200px]">{{ __('Window (days)') }} <input
                        type="number" name="refund_days" min="0" max="365"
                        value="{{ $refundDays }}"
                        class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                </label>
            </section>

            {{-- Yearly toggle --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h2 class="font-serif text-[22px]">{{ __('Yearly billing toggle') }}</h2>
                        <p class="text-[12px] text-ink-500 mt-0.5">
                            {{ __('Shows the Monthly / Yearly switcher on /pricing. Hide it entirely if every plan is monthly-only.') }}
                        </p>
                    </div>
                    <label class="toggle"><input type="hidden" name="yearly_toggle_enabled" value="0"><input
                            type="checkbox" name="yearly_toggle_enabled" value="1"
                            @checked($yearlyEnabled)><span class="track"></span><span
                            class="thumb"></span></label>
                </div>
                <label class="text-[12px] text-ink-700 block max-w-[260px]">{{ __('Yearly discount (%)') }} <input
                        type="number" name="yearly_discount_pct" min="0" max="100"
                        value="{{ $yearlyDiscountPct }}"
                        class="mt-1 w-full px-3 py-1.5 border border-paper-200 rounded-lg bg-white text-[13px] font-mono" />
                    <span
                        class="block text-[10.5px] text-ink-500 mt-0.5">{{ __('Yearly price = monthly × 12 × (1 − discount%).') }}</span>
                </label>
            </section>

            {{-- Auto-renewing subscriptions --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-serif text-[22px]">{{ __('Auto-renewing subscriptions') }}</h2>
                        <p class="text-[12px] text-ink-500 mt-0.5 max-w-[560px]">
                            {{ __('When on, timed paid plans (monthly / yearly) renew automatically — the gateway charges the card each cycle and the plan validity extends on its own. Free, lifetime, and custom-quote plans never recur, and any gateway without recurring support stays a one-time charge. Turn this off to bill every purchase once.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (['Stripe', 'PayPal', 'Razorpay', 'Paddle', 'Mollie', 'Paystack', 'Flutterwave', 'Authorize.Net', 'Braintree', 'Square'] as $g)
                                <span
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-wa-mint border border-wa-green/30 text-wa-deep text-[10.5px] font-semibold">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="M20 6L9 17l-5-5" />
                                    </svg>{{ $g }}
                                </span>
                            @endforeach
                        </div>
                        <p class="text-[10.5px] text-ink-500 mt-2 max-w-[560px]">
                            {{ __('Note: Braintree only auto-renews once you set a Plan ID in its gateway credentials. Square bills each cycle by emailing the customer an invoice (no silent card charge). All renewals require the gateway webhook URL to be set to /payment/webhook/{slug}.') }}
                        </p>
                    </div>
                    <label class="toggle"><input type="hidden" name="recurring_enabled" value="0"><input
                            type="checkbox" name="recurring_enabled" value="1"
                            @checked($recurringEnabled)><span class="track"></span><span
                            class="thumb"></span></label>
                </div>
            </section>

            {{-- Countries --}}
            <section class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                <h2 class="font-serif text-[22px]">{{ __('Checkout country list') }}</h2>
                <p class="text-[12px] text-ink-500 mt-0.5 mb-3">
                    {{ __('The options shown in the "Country" selector on the checkout billing form (/checkout) — used for the invoice address. One country per line; order is preserved.') }}
                </p>
                <textarea name="countries" rows="8"
                    class="w-full px-3 py-2 border border-paper-200 rounded-lg bg-white text-[13px] font-mono">{{ $countries }}</textarea>
            </section>

            <div class="flex items-center justify-end gap-2">
                <button type="submit"
                    class="px-5 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12.5px] font-semibold">{{ __('Save settings') }}</button>
            </div>
        </form>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <a href="{{ route('admin.coupons.index') }}"
                class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card hover:border-wa-deep transition">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('Coupons') }}</div>
                <div class="font-serif text-[22px] mt-1">{{ __('Manage discount codes') }}</div>
                <p class="text-[12px] text-ink-500 mt-1">Add / edit / disable any coupon.
                    {{ \App\Models\Coupon::count() }} configured.</p>
            </a>
            <a href="{{ route('admin.pricing-faqs.index') }}"
                class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card hover:border-wa-deep transition">
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('FAQs') }}</div>
                <div class="font-serif text-[22px] mt-1">{{ __('Edit pricing-page FAQs') }}</div>
                <p class="text-[12px] text-ink-500 mt-1">Accordion items shown below the plans.
                    {{ \App\Models\PricingFaq::count() }} configured.</p>
            </a>
        </div>

    </main>
</x-layouts.admin>
