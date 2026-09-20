<x-layouts.user :title="__('Invoice settings')" nav-key="more" page="user-invoices-settings">
    <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('user.invoices.index') }}" class="w-8 h-8 rounded-full border border-paper-200 bg-paper-0 hover:bg-paper-50 grid place-items-center"><svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3L5 8l5 5"/></svg></a>
            <h1 class="font-serif text-[26px] leading-none">{{ __('Invoice') }} <span class="italic text-wa-deep">{{ __('settings') }}</span></h1>
        </div>
        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error') || $errors->any())<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') ?: $errors->first() }}</div>@endif
        @include('user.invoices._settings_form')
    </main>
</x-layouts.user>
