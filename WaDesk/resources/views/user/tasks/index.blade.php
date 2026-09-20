@php
    $prBadge = ['high' => 'bg-accent-coral/10 text-accent-coral', 'medium' => 'bg-amber-100 text-amber-700', 'low' => 'bg-paper-100 text-ink-500'];
    $sections = [
        'overdue'  => ['label' => __('Overdue'),  'accent' => 'text-accent-coral'],
        'today'    => ['label' => __('Today'),    'accent' => 'text-wa-deep'],
        'upcoming' => ['label' => __('Upcoming'), 'accent' => 'text-ink-800'],
        'no_date'  => ['label' => __('No date'),   'accent' => 'text-ink-500'],
    ];
@endphp
<x-layouts.user :title="__('Tasks')" nav-key="more" page="user-tasks-index">
    <main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7 space-y-6">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <div class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-500 mb-2">{{ __('CRM') }}</div>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[38px] leading-none">{{ __('My') }} <span class="italic text-wa-deep">{{ __('tasks') }}</span></h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-xl">{{ __('Follow-ups and to-dos — assign them, prioritize, and get a reminder when they are due.') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <div class="inline-flex rounded-full border border-paper-200 overflow-hidden text-[12px]">
                    <a href="{{ route('user.tasks.index', ['scope' => 'mine']) }}" class="px-3 py-1.5 {{ $scope === 'mine' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:bg-paper-100' }}">{{ __('Mine') }}</a>
                    <a href="{{ route('user.tasks.index', ['scope' => 'all']) }}" class="px-3 py-1.5 {{ $scope === 'all' ? 'bg-wa-deep text-paper-0' : 'text-ink-600 hover:bg-paper-100' }}">{{ __('All') }}</a>
                </div>
                <button type="button" id="task-open" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">+ {{ __('New task') }}</button>
            </div>
        </div>

        @if (session('success'))<div class="bg-wa-mint border border-wa-green/30 rounded-xl px-4 py-2.5 text-[12.5px] text-wa-deep font-mono">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">{{ session('error') }}</div>@endif
        @if ($errors->any())<div class="bg-accent-coral/10 border border-accent-coral/40 rounded-xl px-4 py-3 text-[12.5px] text-accent-coral">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

        <x-crm.how-to :steps="[
            __('Add a task with a <b>due date</b>, a <b>priority</b> and who it is <b>for</b> (a teammate or yourself).'),
            __('Tasks sort into <b>Overdue</b>, <b>Today</b> and <b>Upcoming</b> so the urgent ones surface first.'),
            __('You get an in-app + WhatsApp <b>reminder</b> near the due time. Tick it <b>done</b> when finished.'),
        ]" />

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
            @foreach ($sections as $key => $meta)
                <div class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card overflow-hidden">
                    <div class="px-5 py-3 border-b border-paper-200 flex items-center justify-between">
                        <span class="text-[13px] font-semibold {{ $meta['accent'] }}">{{ $meta['label'] }}</span>
                        <span class="text-[11px] font-mono text-ink-400">{{ count($buckets[$key]) }}</span>
                    </div>
                    <div class="divide-y divide-paper-100">
                        @forelse ($buckets[$key] as $t)
                            <div class="flex items-start gap-3 px-5 py-3">
                                <form method="POST" action="{{ route('user.tasks.complete', $t->id) }}" class="shrink-0 mt-0.5">
                                    @csrf
                                    <button type="submit" class="w-4.5 h-4.5 w-[18px] h-[18px] rounded-md border border-paper-300 hover:border-wa-deep grid place-items-center" aria-label="{{ __('Complete') }}"></button>
                                </form>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[13px] text-ink-900 leading-snug">{{ $t->title }}</div>
                                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded {{ $prBadge[$t->priority] ?? $prBadge['low'] }}">{{ $t->priority }}</span>
                                        @if ($t->due_at)<span class="text-[10.5px] text-ink-500">{{ $t->due_at->format('d M, H:i') }}</span>@endif
                                        @if ($t->relatedLabel())<span class="text-[10.5px] text-wa-deep">· {{ $t->relatedLabel() }}</span>@endif
                                        @if ($t->assignee_id && $t->assignee?->name)<span class="text-[10.5px] text-ink-400">· {{ $t->assignee->name }}</span>@endif
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('user.tasks.destroy', $t->id) }}" class="shrink-0">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="w-6 h-6 rounded-md grid place-items-center text-ink-400 hover:text-accent-coral hover:bg-accent-coral/10" aria-label="{{ __('Delete') }}">
                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 8h8"/></svg>
                                    </button>
                                </form>
                            </div>
                        @empty
                            <div class="px-5 py-6 text-center text-[12px] text-ink-400">{{ __('Nothing here.') }}</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        @if ($doneRecent->isNotEmpty())
            <details class="bg-paper-0 border border-paper-200 rounded-[14px] shadow-card p-5">
                <summary class="cursor-pointer text-[13px] font-semibold text-ink-700">{{ __('Recently completed') }} ({{ $doneRecent->count() }})</summary>
                <div class="mt-3 space-y-1.5">
                    @foreach ($doneRecent as $t)
                        <div class="flex items-center justify-between gap-3 text-[12.5px]">
                            <span class="text-ink-500 line-through truncate">{{ $t->title }}</span>
                            <form method="POST" action="{{ route('user.tasks.complete', $t->id) }}">@csrf<button type="submit" class="text-[11px] text-wa-deep hover:underline shrink-0">{{ __('Reopen') }}</button></form>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </main>

    {{-- New-task modal --}}
    <div id="task-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-ink-900/40 p-4">
        <form method="POST" action="{{ route('user.tasks.store') }}" class="bg-paper-0 rounded-2xl shadow-xl w-full max-w-md p-5 space-y-3">
            @csrf
            <div class="flex items-center justify-between">
                <div class="text-[15px] font-serif">{{ __('New task') }}</div>
                <button type="button" id="task-close" class="w-7 h-7 grid place-items-center rounded-lg hover:bg-paper-100 text-ink-500" aria-label="{{ __('Close') }}">
                    <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 4l8 8M12 4l-8 8"/></svg>
                </button>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Task') }}</span>
                <input name="title" required maxlength="255" placeholder="{{ __('e.g. Call Acme about renewal') }}" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            <div class="grid grid-cols-2 gap-3">
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Priority') }}</span>
                    <select name="priority" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                        <option value="medium">{{ __('Medium') }}</option><option value="high">{{ __('High') }}</option><option value="low">{{ __('Low') }}</option>
                    </select></label>
                <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Due') }}</span>
                    <input name="due_at" type="datetime-local" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></label>
            </div>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Assign to') }}</span>
                <select name="assignee_id" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep">
                    @foreach ($members as $m)<option value="{{ $m->id }}" @selected($m->id === $me)>{{ $m->name ?: ('#' . $m->id) }}</option>@endforeach
                </select></label>
            <label class="block"><span class="text-[11px] font-semibold text-ink-700">{{ __('Notes') }}</span>
                <textarea name="notes" rows="2" maxlength="5000" class="mt-1 w-full rounded-xl border border-paper-200 bg-paper-0 px-3 py-2 text-[12.5px] focus:outline-none focus:border-wa-deep"></textarea></label>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" id="task-cancel" class="px-4 py-2 rounded-full border border-paper-200 text-[12px] font-semibold text-ink-700 hover:bg-paper-100">{{ __('Cancel') }}</button>
                <button type="submit" class="px-4 py-2 rounded-full bg-wa-deep hover:bg-wa-teal text-paper-0 text-[12px] font-semibold">{{ __('Create task') }}</button>
            </div>
        </form>
    </div>
</x-layouts.user>
