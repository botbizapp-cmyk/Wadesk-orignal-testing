@php
    $u = auth()->user();
    $cfg = $u?->current_workspace_id
        ? \App\Models\WaProviderConfig::query()->forWorkspace($u->current_workspace_id)->first()
        : null;
    $sf = $u?->current_workspace_id
        ? \App\Models\WaStorefront::where('workspace_id', $u->current_workspace_id)->first()
        : null;
@endphp
<x-layouts.user :title="__('Invoices')" nav-key="connect" page="user-store-invoices">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
        <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6 items-start">
            @include('user.store._sidebar', ['current' => 'invoices', 'cfg' => $cfg, 'sf' => $sf])
            <section class="min-w-0">
                @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono mb-4">{{ session('success') }}</div>@endif
                @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral mb-4">{{ session('error') }}</div>@endif
                @include('user.invoices._panel', ['panelSource' => 'own'])
            </section>
        </div>
    </main>
</x-layouts.user>
