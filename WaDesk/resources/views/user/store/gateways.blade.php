<x-layouts.user :title="__('Checkout gateways')" nav-key="connect" page="user-store-gateways">
    @php
        $u   = auth()->user();
        $cfg = $u?->current_workspace_id
            ? \App\Models\WaProviderConfig::query()->forWorkspace($u->current_workspace_id)->first()
            : null;
        $sf  = $u?->current_workspace_id
            ? \App\Models\WaStorefront::where('workspace_id', $u->current_workspace_id)->first()
            : null;
    @endphp
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
            @include('user.store._sidebar', ['current' => 'gateways', 'cfg' => $cfg, 'sf' => $sf])

            <section class="space-y-5 max-w-3xl">
                <div>
                    <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500">{{ __('Store / Checkout gateways') }}</div>
                    <h1 class="font-serif text-[26px] sm:text-[30px] lg:text-[34px] leading-tight tracking-[-0.02em]">{{ __('Checkout') }} <span class="italic text-wa-deep">{{ __('gateways') }}</span></h1>
                    <p class="text-[13px] text-ink-600 mt-1 leading-relaxed">
                        {{ __('Add your OWN payment gateway keys so in-chat checkout charges INTO your account. Works on both WhatsApp Business API and Unofficial numbers. Paste the keys the gateway names in its docs — one per line.') }}
                    </p>
                </div>

                @if (session('status'))
                    <div class="bg-wa-mint border border-wa-green/30 rounded-lg px-4 py-2 text-[12.5px] text-wa-deep font-mono">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="bg-accent-coral/10 border border-accent-coral/40 rounded-lg px-4 py-2 text-[12.5px] text-[#A1431F]">{{ $errors->first() }}</div>
                @endif

                <div class="space-y-2.5">
                    @foreach ($catalog as $slug => $meta)
                        @php $row = $configured[$slug] ?? null; $isOn = $row && $row->active; @endphp
                        <details class="bg-paper-0 border border-paper-200 rounded-xl shadow-card overflow-hidden group" @if ($row) open @endif>
                            <summary class="flex items-center gap-3 px-4 py-3 cursor-pointer select-none list-none">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-semibold text-[13.5px] text-ink-900">{{ $meta['name'] }}</span>
                                        @if ($row)
                                            <span class="text-[10px] font-mono px-1.5 py-0.5 rounded-full {{ $isOn ? 'bg-wa-mint text-wa-deep' : 'bg-paper-100 text-ink-500' }}">{{ $isOn ? __('active') : __('off') }}</span>
                                            <span class="text-[10px] font-mono text-ink-400">{{ $row->mode }}</span>
                                        @endif
                                    </div>
                                    <div class="text-[11.5px] text-ink-500 truncate">{{ $meta['desc'] }}</div>
                                </div>
                                <svg class="w-4 h-4 text-ink-400 transition-transform group-open:rotate-180" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 6l4 4 4-4"/></svg>
                            </summary>

                            <div class="px-4 pb-4 pt-1 border-t border-paper-100">
                                <form method="POST" action="{{ route('user.store.gateways.save', $slug) }}" class="space-y-3" autocomplete="off">
                                    @csrf
                                    @php $fset = $fields[$slug] ?? []; @endphp
                                    @if (!empty($fset))
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                            @foreach ($fset as $fkey => $f)
                                                @php
                                                    $ftype  = ($f['type'] ?? 'text') === 'password' ? 'password' : 'text';
                                                    $stored = $row ? $row->getCredential($fkey) : null;
                                                    // Never pre-fill a secret; safe to show non-password keys (key ids).
                                                    $prefill = ($ftype === 'text' && $stored) ? $stored : '';
                                                    $ph = $ftype === 'password' && $stored ? '•••••••• ' . __('stored') : ($f['hint'] ?? '');
                                                @endphp
                                                <label class="block">
                                                    <span class="text-[11px] font-semibold text-ink-700">{{ $f['label'] ?? $fkey }}@if ($f['required'] ?? false)<span class="text-accent-coral">*</span>@endif</span>
                                                    <input type="{{ $ftype }}" name="creds[{{ $fkey }}]" value="{{ $prefill }}" autocomplete="new-password"
                                                        placeholder="{{ $ph }}"
                                                        class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12px] font-mono focus:outline-none focus:border-wa-deep" />
                                                    @if (($f['hint'] ?? '') && !($ftype === 'password' && $stored))
                                                        <span class="text-[10px] text-ink-500">{{ $f['hint'] }}</span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        </div>
                                        @if ($row)
                                            <span class="text-[10.5px] text-ink-500 block">{{ __('Leave a field blank to keep its stored value.') }}</span>
                                        @endif
                                    @else
                                        <label class="block">
                                            <span class="text-[11px] font-semibold text-ink-700">{{ __('Credentials') }}</span>
                                            <textarea name="creds_raw" rows="3" class="w-full mt-1 px-3 py-2 border border-paper-200 rounded-lg bg-paper-0 text-[12.5px] font-mono focus:outline-none focus:border-wa-deep"
                                                placeholder="key: value">@if(false){{-- never echo stored secrets --}}@endif</textarea>
                                            <span class="text-[10.5px] text-ink-500 mt-0.5 block">{{ __('One key per line, e.g. secret_key: … — see the gateway\'s API docs.') }}</span>
                                        </label>
                                    @endif

                                    {{-- Webhook URL — MUST be pasted into the gateway dashboard so payments confirm. --}}
                                    <div class="rounded-lg bg-paper-50 border border-paper-200 px-3 py-2">
                                        <span class="text-[11px] font-semibold text-ink-700">{{ __('Webhook URL') }}</span>
                                        <span class="text-[10.5px] text-ink-500">— {{ __('paste into your :name dashboard so payments confirm automatically', ['name' => $meta['name']]) }}</span>
                                        <code class="block mt-1 font-mono text-[11px] text-wa-deep break-all select-all">{{ $webhookBase }}{{ $slug }}</code>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <label class="text-[11.5px] text-ink-700">{{ __('Mode') }}
                                            <select name="mode" class="ml-1 px-2 py-1 border border-paper-200 rounded-lg bg-paper-0 text-[12px]">
                                                <option value="live" @selected(($row->mode ?? 'live') === 'live')>{{ __('Live') }}</option>
                                                <option value="test" @selected(($row->mode ?? '') === 'test')>{{ __('Test') }}</option>
                                            </select>
                                        </label>
                                        <label class="text-[11.5px] text-ink-700 inline-flex items-center gap-1.5">
                                            <input type="checkbox" name="active" value="1" @checked(!$row || $row->active) class="w-4 h-4 rounded accent-wa-deep"> {{ __('Active') }}
                                        </label>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="submit" class="px-3.5 py-1.5 rounded-lg bg-wa-deep text-paper-0 text-[12px] font-semibold hover:bg-wa-teal">{{ __('Save') }}</button>
                                    </div>
                                </form>
                                @if ($row)
                                    <form method="POST" action="{{ route('user.store.gateways.destroy', $slug) }}" class="mt-2"
                                        onsubmit="return confirm('{{ __('Remove these gateway keys?') }}')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-[11px] font-semibold text-accent-coral hover:underline">{{ __('Remove keys') }}</button>
                                    </form>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        </div>
    </main>
</x-layouts.user>
