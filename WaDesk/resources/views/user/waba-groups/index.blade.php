<x-layouts.user :title="__('WhatsApp Groups')" nav-key="waba-groups" page="waba-groups">
<main class="max-w-none mx-auto px-4 sm:px-6 lg:px-7 py-7">
    <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">

        {{-- ── Left rail ─────────────────────────────────────────────── --}}
        <aside class="space-y-3">
            <div class="border border-paper-200 rounded-2xl bg-paper-0 p-4 shadow-card">
                <div class="flex items-start justify-between gap-2">
                    <span class="w-9 h-9 rounded-xl bg-wa-bubble flex items-center justify-center text-wa-deep">
                        <svg class="w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="5.5" cy="5.5" r="2" /><circle cx="10.5" cy="5.5" r="2" />
                            <path d="M2 12.5a3.5 3.5 0 0 1 7 0M7 12.5a3.5 3.5 0 0 1 7 0" />
                        </svg>
                    </span>
                    @if ($account)
                        <span @class([
                            'text-[10px] font-mono uppercase tracking-[0.14em] px-2 py-1 rounded-full',
                            'bg-wa-mint text-wa-deep' => $eligible,
                            'bg-paper-100 text-ink-500' => ! $eligible,
                        ])>{{ $eligible ? __('Eligible') : __('Not eligible') }}</span>
                    @endif
                </div>
                <h2 class="font-serif text-[18px] mt-3 leading-tight">{{ __('Groups') }}</h2>
                <p class="font-mono text-[11px] text-ink-500 mt-1">
                    {{ $account?->phone_number ?: __('No WhatsApp Business number') }}
                </p>
            </div>

            @if ($accounts->count() > 1)
                <div class="border border-paper-200 rounded-2xl bg-paper-0 p-2 shadow-card">
                    <p class="text-[10px] uppercase tracking-[0.16em] text-ink-500 font-mono px-2 py-2">
                        {{ __('Number') }}
                    </p>
                    @foreach ($accounts as $a)
                        <a href="{{ route('user.waba-groups.index', ['account' => $a->id]) }}"
                            @class([
                                'block px-2 py-2 rounded-xl text-[12.5px] truncate',
                                'bg-wa-deep/8 text-wa-deep font-semibold' => $account && $a->id === $account->id,
                                'hover:bg-paper-50' => ! ($account && $a->id === $account->id),
                            ])>{{ $a->display_label ?: $a->phone_number }}</a>
                    @endforeach
                </div>
            @endif

            <div class="border border-wa-green/30 bg-wa-bubble/50 rounded-2xl p-4 text-[12px] leading-relaxed">
                <p class="font-semibold text-wa-deep mb-1">{{ __('How groups work') }}</p>
                <p class="text-ink-600">
                    {{ __('Groups are invite-only. You create the group, WhatsApp gives you an invite link, and you share that link — people choose to join. There is no way to add someone directly.') }}
                </p>
                <p class="text-ink-600 mt-2">
                    {{ __('Every message you send is charged once per person who receives it. A message to 100 members costs 100 sends.') }}
                </p>
            </div>
        </aside>

        {{-- ── Main ──────────────────────────────────────────────────── --}}
        <section class="space-y-5">
            <div>
                <p class="text-[10px] uppercase tracking-[0.18em] font-mono text-ink-500">
                    {{ __('WhatsApp') }} <span class="mx-1">/</span> {{ __('Groups') }}
                </p>
                <h1 class="font-serif font-normal tracking-tight text-[30px] sm:text-[36px] lg:text-[44px] leading-none mt-1">
                    {{ __('Group') }} <span class="italic text-wa-deep">{{ __('messaging') }}</span>
                </h1>
                <p class="text-[13px] text-ink-600 mt-2 max-w-2xl">
                    {{ __('Create WhatsApp groups from your business number, share the invite link, and handle join requests. Conversations appear in your inbox like any other chat.') }}
                </p>
            </div>

            @if (session('status'))
                <div class="rounded-2xl border border-wa-green/40 bg-wa-bubble/60 px-4 py-3 text-[13px] text-wa-deep">
                    {{ session('status') }}
                </div>
            @endif
            @error('group')
                <div class="rounded-2xl border border-accent-coral/40 bg-accent-coral/5 px-4 py-3 text-[13px] text-accent-coral">
                    {{ $message }}
                </div>
            @enderror

            @if (! $account)
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card text-center">
                    <p class="font-serif text-[20px]">{{ __('No WhatsApp Business number connected') }}</p>
                    <p class="text-[13px] text-ink-600 mt-2">
                        {{ __('Groups need a WhatsApp number connected through the official WhatsApp Business Platform.') }}
                    </p>
                    <p class="text-[12px] text-ink-500 mt-3">{{ setup_hint('devices') }}</p>
                </div>
            @elseif (! $eligible)
                {{-- Meta returns 131215 for any number that is not an Official
                     Business Account. Nothing on this page can work, so say why
                     plainly instead of showing controls that will fail. --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-6 shadow-card">
                    <p class="font-serif text-[20px]">{{ __('This number cannot use groups yet') }}</p>
                    <p class="text-[13px] text-ink-600 mt-2 max-w-xl">
                        {{ __('WhatsApp only opens the Groups feature to Official Business Accounts — the verified tier with the green badge. Your number is connected and working for normal chats; it just has not been granted group access.') }}
                    </p>
                    <p class="text-[12px] text-ink-500 mt-3">
                        {{ __('Apply for Official Business Account status in WhatsApp Manager, then reload this page.') }}
                    </p>
                </div>
            @else
                {{-- KPI strip --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach ([
                        ['label' => __('Groups'),        'value' => $stats['total'],        'sub' => __('on this number')],
                        ['label' => __('Members'),       'value' => $stats['participants'], 'sub' => __('across all groups')],
                        ['label' => __('Join requests'), 'value' => $stats['pending'],      'sub' => __('waiting for you')],
                        ['label' => __('Suspended'),     'value' => $stats['suspended'],    'sub' => __('blocked by WhatsApp')],
                    ] as $kpi)
                        <div class="bg-paper-0 border border-paper-200 rounded-2xl p-4 shadow-card">
                            <p class="text-[10px] uppercase tracking-[0.16em] font-mono text-ink-500">{{ $kpi['label'] }}</p>
                            <p class="text-[26px] font-serif leading-none mt-2">{{ number_format($kpi['value']) }}</p>
                            <p class="text-[10px] text-ink-400 leading-snug mt-1">{{ $kpi['sub'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Create --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl p-5 shadow-card">
                    <p class="font-serif text-[18px]">{{ __('Create a group') }}</p>
                    <form method="POST" action="{{ route('user.waba-groups.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="account_id" value="{{ $account->id }}">
                        <div>
                            <label class="text-[12px] font-medium">{{ __('Group name') }}</label>
                            <input name="subject" maxlength="128" required
                                class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[13px]">
                            <p class="text-[10px] text-ink-400 leading-snug mt-1">
                                {{ __('What members see at the top of the chat. Up to 128 characters.') }}
                            </p>
                        </div>
                        <div>
                            <label class="text-[12px] font-medium">{{ __('Who can join') }}</label>
                            <select name="join_approval_mode"
                                class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[13px]">
                                <option value="auto_approve">{{ __('Anyone with the link joins straight away') }}</option>
                                <option value="approval_required">{{ __('You approve each person first') }}</option>
                            </select>
                            <p class="text-[10px] text-ink-400 leading-snug mt-1">
                                {{ __('Approval gives you a queue to review before anyone is let in.') }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-[12px] font-medium">{{ __('Description') }}</label>
                            <textarea name="description" rows="2" maxlength="2048"
                                class="mt-1 w-full rounded-xl border border-paper-200 px-3 py-2 text-[13px]"></textarea>
                            <p class="text-[10px] text-ink-400 leading-snug mt-1">
                                {{ __('Optional. Shown on the group info screen.') }}
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <button class="rounded-full bg-wa-deep text-white px-5 py-2 text-[13px] font-medium">
                                {{ __('Create group') }}
                            </button>
                        </div>
                    </form>
                </div>

                {{-- List --}}
                <div class="bg-paper-0 border border-paper-200 rounded-2xl shadow-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-paper-200">
                        <p class="font-serif text-[18px]">{{ __('Your groups') }}</p>
                    </div>

                    @forelse ($groups as $g)
                        @php $pending = (array) ($g->meta_json['join_requests'] ?? []); @endphp
                        <div class="px-5 py-4 border-b border-paper-100 last:border-0" data-group-row data-group-id="{{ $g->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-[14px] truncate">{{ $g->subject ?: __('Untitled group') }}</p>
                                    <p class="text-[11px] text-ink-500 mt-0.5">
                                        {{ trans_choice('{0}No members|{1}1 member|[2,*]:count members', $g->participant_count, ['count' => number_format($g->participant_count)]) }}
                                        @if ($g->join_approval_mode === 'approval_required')
                                            <span class="mx-1">·</span>{{ __('approval required') }}
                                        @endif
                                    </p>
                                    @if ($g->suspended)
                                        <p class="text-[11px] text-accent-coral mt-1">
                                            {{ __('WhatsApp has suspended this group. You cannot message it until they restore it.') }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <form method="POST" action="{{ route('user.waba-groups.sync', $g->id) }}">
                                        @csrf
                                        <button class="text-[12px] rounded-full border border-paper-200 px-3 py-1.5 hover:bg-paper-50">
                                            {{ __('Refresh') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('user.waba-groups.destroy', $g->id) }}"
                                        onsubmit="return confirm('{{ __('Delete this group and remove everyone from it?') }}')">
                                        @csrf @method('DELETE')
                                        <button class="text-[12px] rounded-full border border-paper-200 px-3 py-1.5 text-accent-coral hover:bg-accent-coral/5">
                                            {{ __('Delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            {{-- Invite link. Absent is a REAL state, not an error:
                                 Meta issues it on a webhook moments after create. --}}
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                @if ($g->awaitingInviteLink())
                                    <span class="text-[12px] text-ink-500">{{ __('Invite link is being created by WhatsApp…') }}</span>
                                @else
                                    <code class="text-[11px] bg-paper-50 border border-paper-200 rounded-lg px-2 py-1 truncate max-w-full">{{ $g->invite_link }}</code>
                                    <button type="button" class="text-[12px] rounded-full border border-paper-200 px-3 py-1.5 hover:bg-paper-50"
                                        data-copy-link="{{ $g->invite_link }}">{{ __('Copy') }}</button>
                                @endif
                                <form method="POST" action="{{ route('user.waba-groups.reset-link', $g->id) }}"
                                    onsubmit="return confirm('{{ __('Create a new link? Everyone you already sent the old link to will not be able to use it.') }}')">
                                    @csrf
                                    <button class="text-[12px] rounded-full border border-paper-200 px-3 py-1.5 hover:bg-paper-50">
                                        {{ __('New link') }}
                                    </button>
                                </form>
                            </div>

                            @if ($pending)
                                <div class="mt-3 rounded-xl border border-paper-200 bg-paper-50 p-3">
                                    <p class="text-[10px] uppercase tracking-[0.16em] font-mono text-ink-500">
                                        {{ __('Waiting to join') }}
                                    </p>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($pending as $jid => $jr)
                                            <div class="flex items-center justify-between gap-2 text-[12px]">
                                                <span class="font-mono">{{ $jr['wa_id'] ?? $jid }}</span>
                                                <span class="flex gap-1.5">
                                                    <button type="button" class="rounded-full bg-wa-deep text-white px-3 py-1"
                                                        data-jr-action="approve" data-jr-id="{{ $jid }}">{{ __('Approve') }}</button>
                                                    <button type="button" class="rounded-full border border-paper-200 px-3 py-1"
                                                        data-jr-action="reject" data-jr-id="{{ $jid }}">{{ __('Reject') }}</button>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center">
                            <p class="text-[13px] text-ink-600">{{ __('No groups yet. Create one above to get started.') }}</p>
                        </div>
                    @endforelse
                </div>
            @endif
        </section>
    </div>
</main>

</x-layouts.user>
