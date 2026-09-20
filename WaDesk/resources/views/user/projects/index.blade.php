@php
    $cfg = [
        'in_progress' => ['label' => __('In progress'), 'accent' => 'text-wa-deep',    'bar' => 'bg-wa-deep'],
        'overdue'     => ['label' => __('Overdue'),     'accent' => 'text-accent-coral','bar' => 'bg-accent-coral'],
        'completed'   => ['label' => __('Completed'),   'accent' => 'text-ink-500',     'bar' => 'bg-wa-green'],
    ];
@endphp
<x-layouts.user :title="__('Projects')" nav-key="more" page="user-projects-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('Your') }} <span class="italic text-wa-deep">{{ __('projects') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Track delivery after the sale — progress, due dates and who owns each project.') }}</p>
            </div>
            <button type="button" id="pj-open" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold shrink-0">+ {{ __('New project') }}</button>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

        <x-crm.how-to :steps="[
            __('Click <b>+ New project</b>, name it, link a company and set a <b>due date</b>.'),
            __('It starts in the <b>In progress</b> column. Drag the <b>slider</b> on the card as work gets done.'),
            __('At <b>100%</b> it moves to <b>Completed</b> by itself. If the due date passes, it moves to <b>Overdue</b>.'),
        ]" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            @foreach ($cfg as $key => $meta)
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <span class="text-[13px] font-semibold {{ $meta['accent'] }}">{{ $meta['label'] }}</span>
                        <span class="text-[11px] font-mono text-ink-400">{{ count($cols[$key]) }}</span>
                    </div>
                    <div class="p-3 space-y-3">
                        @forelse ($cols[$key] as $p)
                            <div class="rounded-xl border border-paper-200 p-3.5">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-[13px] font-semibold text-ink-900 leading-snug">{{ $p->name }}</div>
                                    <form method="POST" action="{{ route('user.projects.destroy', $p->id) }}">@csrf @method('DELETE')
                                        <button type="submit" class="w-5 h-5 rounded grid place-items-center text-ink-400 hover:text-accent-coral" aria-label="Delete">
                                            <svg viewBox="0 0 16 16" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h8"/></svg>
                                        </button>
                                    </form>
                                </div>
                                <div class="flex items-center gap-2 mt-1 text-[10.5px] text-ink-500">
                                    @if ($p->company_id && $p->company?->name)<span>{{ $p->company->name }}</span>@endif
                                    @if ($p->owner_id && $p->owner?->name)<span>· {{ $p->owner->name }}</span>@endif
                                    @if ($p->due_date)<span class="{{ $p->isOverdue() ? 'text-accent-coral' : '' }}">· {{ $p->due_date->format('d M') }}</span>@endif
                                </div>
                                {{-- progress --}}
                                <div class="mt-2.5">
                                    <div class="flex items-center justify-between text-[10.5px] text-ink-500 mb-1"><span>{{ __('Progress') }}</span><span class="font-mono pj-pct">{{ $p->progress }}%</span></div>
                                    <div class="h-1.5 rounded-full bg-paper-200 overflow-hidden"><div class="h-full {{ $meta['bar'] }}" style="width: {{ $p->progress }}%"></div></div>
                                    @if ($key !== 'completed')
                                        <input type="range" min="0" max="100" step="5" value="{{ $p->progress }}"
                                            class="pj-range w-full mt-2 accent-wa-deep" data-id="{{ $p->id }}">
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="px-2 py-6 text-center text-[12px] text-ink-400">{{ __('Nothing here.') }}</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </main>

    {{-- New-project modal --}}
    <div id="pj-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink-900/40 p-4">
        <form method="POST" action="{{ route('user.projects.store') }}" class="bg-paper-0 rounded-2xl shadow-xl w-full max-w-md p-5 space-y-3">
            @csrf
            <div class="flex items-center justify-between">
                <div class="text-[15px] font-serif">{{ __('New project') }}</div>
                <button type="button" id="pj-close" class="w-7 h-7 grid place-items-center rounded-lg hover:bg-paper-100 text-ink-500" aria-label="Close"><svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l8 8M12 4l-8 8"/></svg></button>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Project name') }}</span>
                <input name="name" required maxlength="255" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Owner') }}</span>
                    <select name="owner_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">@foreach ($members as $m)<option value="{{ $m->id }}" @selected($m->id === $me)>{{ $m->name ?: ('#' . $m->id) }}</option>@endforeach</select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Company') }}</span>
                    <select name="company_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"><option value="">{{ __('— none —') }}</option>@foreach ($companies as $co)<option value="{{ $co->id }}">{{ $co->name ?: ('#' . $co->id) }}</option>@endforeach</select></label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Start') }}</span>
                    <input name="start_date" type="date" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Due') }}</span>
                    <input name="due_date" type="date" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Notes') }}</span>
                <textarea name="description" rows="2" maxlength="5000" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></textarea></label>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" id="pj-cancel" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Cancel') }}</button>
                <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Create') }}</button>
            </div>
        </form>
    </div>
    <input type="hidden" id="pj-csrf" value="{{ csrf_token() }}">
</x-layouts.user>
