<x-layouts.admin :title="__('Mobile push settings')" admin-key="settings" page="admin-settings-mobile-push">

    <header class="h-16 bg-paper-0 hairline-b border-b border-paper-200 flex items-center px-4 sm:px-7 gap-4 sticky top-0 z-30">
        <div class="flex items-center gap-2 text-[12px] font-mono text-ink-500 shrink-0">
            <a href="{{ url('/admin') }}" class="uppercase tracking-[0.16em] hover:text-ink-900">{{ __('Admin') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <a href="{{ url('/admin/settings') }}" class="hover:text-ink-900">{{ __('Settings') }}</a>
            <svg viewBox="0 0 12 12" class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 3l3 3-3 3" /></svg>
            <span class="text-ink-900 normal-case tracking-normal">{{ __('Mobile push') }}</span>
        </div>
        <div class="relative flex-1 max-w-[520px] ml-4 hidden md:block">
            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5 absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-500" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="5" /><path d="m11 11 3 3" /></svg>
            <input class="w-full rounded-full bg-paper-50 border border-paper-200 pl-10 pr-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep focus:bg-paper-0 transition" placeholder="{{ __('Search inside settings...') }}" />
            <kbd class="absolute right-3 top-1/2 -translate-y-1/2 px-1.5 py-0.5 rounded-md bg-paper-0 border border-paper-200 text-[10px] font-mono text-ink-500">{{ __('CMD K') }}</kbd>
        </div>
        <div class="ml-auto flex items-center gap-2" data-admin-header-right></div>
    </header>

    <main class="px-4 sm:px-7 py-7 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('Admin - Project settings') }}</div>
                <h1 class="font-serif font-normal tracking-[-0.01em] text-[28px] sm:text-[40px] leading-[1.0]">{{ __('Mobile app push') }} <span class="italic text-wa-deep">{{ __('(FCM)') }}</span>.</h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Push notifications wake the mobile app when it is backgrounded or killed, so operators never miss an inbound WhatsApp message. The service-account JSON is encrypted at rest and used only server-side to sign FCM requests. Until it is set, push is off and everything else keeps working.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 pb-1">
                <a href="{{ url('/admin/settings') }}" class="px-4 py-2 hairline border border-paper-200 rounded-full bg-paper-0 hover:bg-paper-50 text-[12px] font-medium">{{ __('All settings') }}</a>
                <button type="submit" form="fcm-form" class="px-4 py-2 rounded-full bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save push settings') }}</button>
            </div>
        </div>

        <x-admin.flash />

        {{-- Status strip --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('Push status') }}</div>
                <div class="text-[16px] font-medium {{ $enabled ? 'text-wa-deep' : 'text-accent-coral' }}">{{ $enabled ? __('Enabled') : __('Off') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('Service account') }}</div>
                <div class="text-[16px] font-medium">{{ $serviceAccountSet ? __('Configured') : __('Not set') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('Project') }}</div>
                <div class="text-[16px] font-medium truncate" title="{{ $saProjectId ?: $projectId }}">{{ $saProjectId ?: ($projectId ?: '—') }}</div>
            </div>
            <div class="rounded-2xl border border-paper-200 bg-paper-0 p-4">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-1">{{ __('Registered devices') }}</div>
                <div class="text-[16px] font-medium">{{ number_format($tokenCount) }}</div>
            </div>
        </div>

        {{-- Body: form (left) + how-to guide (right) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

            {{-- LEFT — the form --}}
            <form id="fcm-form" method="POST" action="{{ route('admin.settings.mobile-push.update') }}" class="rounded-2xl border border-paper-200 bg-paper-0 p-6 space-y-5">
                @csrf

                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('Firebase credentials') }}</div>

                    <label class="block text-[12.5px] font-medium text-ink-800 mb-1.5">{{ __('FCM project id') }} <span class="text-ink-500 font-normal">({{ __('optional — auto-read from the JSON') }})</span></label>
                    <input type="text" name="fcm_project_id" value="{{ old('fcm_project_id', $projectId) }}" placeholder="my-firebase-project"
                        class="w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2.5 text-[13px] focus:outline-none focus:border-wa-deep transition">
                </div>

                <div>
                    <label class="block text-[12.5px] font-medium text-ink-800 mb-1.5">{{ __('Firebase service-account JSON') }}</label>
                    <textarea name="fcm_service_account_json" rows="12"
                        placeholder='{{ $serviceAccountSet ? __("•••••••• already saved — paste a new JSON only to replace it") : "{ \"type\": \"service_account\", \"project_id\": \"...\", \"private_key\": \"-----BEGIN PRIVATE KEY-----...\", \"client_email\": \"...\" }" }}'
                        class="w-full rounded-lg border border-paper-200 bg-paper-0 px-3 py-2.5 text-[12px] font-mono leading-relaxed focus:outline-none focus:border-wa-deep transition"></textarea>
                    <p class="text-[11.5px] text-ink-500 mt-1.5">{{ __('Paste the FULL JSON. Leave blank to keep the existing one. Encrypted at rest — the stored value is never shown back.') }}</p>
                </div>

                @if ($serviceAccountSet)
                    <div class="pt-1 border-t border-paper-200">
                        <div class="text-[12px] text-ink-600 mt-3">
                            {{ __('Current') }}: <code class="px-1.5 py-0.5 rounded bg-paper-100">{{ $clientEmail ?: '—' }}</code>
                        </div>
                        <label class="flex items-center gap-2 text-[12.5px] text-ink-700 mt-3">
                            <input type="checkbox" name="clear_service_account" value="1" class="rounded border-paper-300">
                            {{ __('Remove the saved service account (turns push OFF)') }}
                        </label>
                    </div>
                @endif

                <div class="pt-2">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-wa-deep text-white font-medium text-[13px] hover:bg-wa-teal transition">{{ __('Save push settings') }}</button>
                </div>
            </form>

            {{-- RIGHT — how to get the JSON --}}
            <div class="rounded-2xl border border-paper-200 bg-paper-50 p-6">
                <div class="font-mono text-[10px] uppercase tracking-[0.16em] text-ink-500 mb-4">{{ __('How to get your Firebase service-account JSON') }}</div>
                <ol class="space-y-3">
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">1</span>
                        <span>{{ __('Open') }} <a href="https://console.firebase.google.com/" target="_blank" rel="noopener" class="text-wa-deep font-medium underline">console.firebase.google.com</a> {{ __('and select your project (or create one).') }}</span>
                    </li>
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">2</span>
                        <span>{{ __('Click the') }} <strong>⚙ {{ __('gear') }}</strong> {{ __('(top-left) →') }} <strong>{{ __('Project settings') }}</strong>.</span>
                    </li>
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">3</span>
                        <span>{{ __('Open the') }} <strong>{{ __('Service accounts') }}</strong> {{ __('tab.') }}</span>
                    </li>
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">4</span>
                        <span>{{ __('Click') }} <strong>{{ __('Generate new private key') }}</strong> → <strong>{{ __('Generate key') }}</strong>. {{ __('A') }} <code class="px-1 py-0.5 rounded bg-paper-100 text-[11px]">.json</code> {{ __('file downloads.') }}</span>
                    </li>
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">5</span>
                        <span>{{ __('Open that file, copy the') }} <strong>{{ __('entire') }}</strong> {{ __('contents, and paste it into the box on the left.') }}</span>
                    </li>
                    <li class="flex gap-3 items-start text-[13px] text-ink-700">
                        <span class="w-6 h-6 shrink-0 rounded-full grid place-items-center text-[11px] font-semibold text-white" style="background:#4f46e5">6</span>
                        <span>{{ __('Click') }} <strong>{{ __('Save push settings') }}</strong>. {{ __('Push turns on immediately.') }}</span>
                    </li>
                </ol>
                <p class="text-[11.5px] text-ink-500 mt-5 pt-4 border-t border-paper-200">
                    {{ __('The mobile app must use the SAME Firebase project (the app developer adds google-services.json / GoogleService-Info.plist to the app). "Firebase Cloud Messaging API (V1)" is enabled by default — no extra step.') }}
                </p>
            </div>
        </div>
    </main>
</x-layouts.admin>
