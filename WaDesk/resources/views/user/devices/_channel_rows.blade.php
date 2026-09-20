{{-- Connected WABA + Twilio channel rows.
     Lives in its own partial because #devices-list is REPLACED wholesale by the
     AJAX refresh (user-devices-index.js: list.innerHTML = data.cards). Rendering
     these rows only in index.blade meant every refresh wiped them — the page
     showed 3 channels, then dropped to 1. DevicesController appends this partial
     to `cards` so a refresh rebuilds all three, and card view (which clones
     source.children) keeps seeing them.
     Needs: $multiEngine, $connectedChannels. --}}
                            @if ($multiEngine)
                                {{-- Connected WABA + Twilio accounts appended as rows in the SAME table
 (same columns as the Baileys device rows above). --}}
                                @foreach ($connectedChannels->where('engine', '!=', 'baileys') as $ch)
                                    @php $b = $chBadge[$ch['engine']] ?? ['label' => $ch['engine'], 'cls' => 'bg-paper-100 text-ink-700']; @endphp
                                    <div
                                        class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                        <div class="px-1"></div>
                                        <div class="min-w-0 flex items-center gap-2.5">
                                            <span
                                                class="w-9 h-9 rounded-lg grid place-items-center shrink-0 {{ $b['cls'] }}">
                                                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none"
                                                    stroke="currentColor" stroke-width="1.5">
                                                    <path d="M2.6 11.2 2 14l2.9-.6A6 6 0 1 0 2.6 11.2Z" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="font-semibold text-ink-900 text-[12.5px] truncate">
                                                    {{ $ch['label'] }}</div>
                                                <div class="text-[10.5px] text-ink-500 font-mono truncate">
                                                    {{ $b['label'] }}</div>
                                            </div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-700 truncate">
                                            {{ $ch['phone'] ? mask_phone('+' . $ch['phone']) : '—' }}</div>
                                        <div class="text-[12px] text-ink-500 truncate">—</div>
                                        <div class="min-w-0">
                                            <div class="font-mono text-[11.5px] text-ink-500 truncate">{{ __('live') }}
                                            </div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                        @php
                                            // A row can hold valid credentials while Meta has the number on the
                                            // RETIRED On-Premises API — every send then dies with 133010 while
                                            // this badge says "Connected". Trust Meta's platform_type over our
                                            // own row state. COEXISTENCE / SMB_APP are supported, so only the
                                            // one Meta can no longer deliver for is called out.
                                            $chPlatform = $ch['engine'] === 'waba' ? ($wabaPlatform[$ch['id']] ?? '') : '';
                                            $chDead     = $chPlatform === 'ON_PREMISE';
                                        @endphp
                                        <div>
                                            @if ($chDead)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-accent-coral/15 text-accent-coral border border-accent-coral/40"
                                                    title="{{ __('Meta has this number on the On-Premises API, retired 23 Oct 2025. It cannot send until you verify it and register it on the Cloud API in WhatsApp Manager.') }}"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-accent-coral"></span>{{ __('Not on Cloud API') }}</span>
                                                <span class="block mt-1 text-[10px] text-ink-500 leading-snug max-w-[130px]">{{ __('On-Premises (retired) — cannot send. Migrate in WhatsApp Manager.') }}</span>
                                            @else
                                                <span
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                                @if ($ch['engine'] === 'waba')
                                                    <span class="block mt-1">@include('user.devices._inbound_badge', ['wired' => ($wabaInbound[$ch['id']] ?? null)])</span>
                                                @endif
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                            @if ($ch['engine'] === 'waba')
                                                {{-- Icon actions (match the device-row icon style):
                                                     Health · Manage · Disconnect · Remove.
                                                     $ch['id'] is the WaProviderConfig id. --}}
                                                <a href="{{ route('user.devices.waba.health', $ch['id']) }}"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Account health — live Meta diagnostics') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8h3l1.5-4 3 9 1.5-5H14.5" /></svg>
                                                </a>
                                                {{-- Fix inbound = re-apply the webhook override + verify incoming messages route here. --}}
                                                <form method="POST" action="{{ url('/devices/waba/' . $ch['id'] . '/resubscribe') }}"
                                                    class="inline" data-confirm="{{ __('Re-check & fix inbound for this number? No re-login needed.') }}">
                                                    @csrf
                                                    <button type="submit"
                                                        class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                        title="{{ __('Fix inbound — re-subscribe & verify incoming messages') }}">
                                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                    </button>
                                                </form>
                                                {{-- Register button — HIDDEN.
                                                     POST /{phone_number_id}/register is rejected for SMB numbers:
                                                     Meta answers "(#100) Register endpoint is not available for SMB
                                                     businesses". So for those the button can only ever fail, and it
                                                     surfaced a red "WhatsApp connection could not be completed"
                                                     banner that reads like OUR bug when it is Meta's account state.
                                                     The route + WabaNumberRegistrar are left intact — un-comment
                                                     this block if Meta ever opens /register to SMB numbers.
                                                <form method="POST" action="{{ url('/devices/waba/' . $ch['id'] . '/register') }}"
                                                    class="inline" data-confirm="{{ __('Register this number on the WhatsApp Cloud API? Use this if sending fails with “Account not registered”. If this number is still live on the WhatsApp Business app (coexistence), registering will migrate it off that app.') }}">
                                                    @csrf
                                                    <button type="submit"
                                                        class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                        title="{{ __('Register on Cloud API — fixes “Account not registered” (133010)') }}">
                                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-7" /></svg>
                                                    </button>
                                                </form>
                                                --}}
                                                <button type="button" data-waba-connect="{{ $embeddedSignupReady ? 'embedded' : 'manual' }}"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Manage / reconnect') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="1.8"/><path d="M8 1.8v1.6M8 12.6v1.6M14.2 8h-1.6M3.4 8H1.8M12.4 3.6l-1.2 1.2M4.8 11.2l-1.2 1.2M12.4 12.4l-1.2-1.2M4.8 4.8 3.6 3.6"/></svg>
                                                </button>
                                                {{-- Disconnect = wipe credentials, keep the row. --}}
                                                <form method="POST" action="{{ url('/devices/waba/' . $ch['id'] . '/disconnect') }}"
                                                    class="inline" data-confirm="{{ __('Disconnect this WhatsApp number? It stops sending until you re-authorize it.') }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                        class="w-8 h-8 rounded-lg grid place-items-center text-ink-500 hover:bg-accent-coral/10 hover:text-accent-coral transition"
                                                        title="{{ __('Disconnect') }}">
                                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5v5.5M4.7 4.3a4.6 4.6 0 1 0 6.6 0"/></svg>
                                                    </button>
                                                </form>
                                                {{-- Remove = permanently delete this WABA number from the workspace. --}}
                                                <form method="POST" action="{{ url('/devices/waba/' . $ch['id'] . '/remove') }}"
                                                    class="inline" data-confirm="{{ __('Remove this WhatsApp number from the workspace? This permanently deletes it — you can re-add it later.') }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                        class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                        title="{{ __('Remove') }}">
                                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                                                    </button>
                                                </form>
                                            @elseif ($ch['engine'] === 'twilio')
                                                <button type="button" data-twilio-connect
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Manage') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="1.8"/><path d="M8 1.8v1.6M8 12.6v1.6M14.2 8h-1.6M3.4 8H1.8M12.4 3.6l-1.2 1.2M4.8 11.2l-1.2 1.2M12.4 12.4l-1.2-1.2M4.8 4.8 3.6 3.6"/></svg>
                                                </button>
                                                {{-- Remove = permanently delete this Twilio account from the workspace. --}}
                                                <form method="POST" action="{{ url('/devices/twilio/' . $ch['id'] . '/remove') }}"
                                                    class="inline" data-confirm="{{ __('Remove this Twilio account from the workspace? This permanently deletes it — you can re-add it later.') }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit"
                                                        class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                        title="{{ __('Remove') }}">
                                                        <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5"/></svg>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            {{-- Instagram accounts linked from the Instaflow install. Rendered
 whenever this workspace has linked IG accounts (independent of the WA
 engine count) — the account itself lives on Instaflow; this row is its
 mirror. Needs: $instagramAccounts, $instaflowUrl. --}}
                            @foreach (($instagramAccounts ?? collect()) as $ig)
                                @php
                                    $igHandle = '@' . ltrim((string) ($ig->username ?: $ig->name), '@');
                                    $igLive   = ($ig->status ?? 'connected') === 'connected';
                                @endphp
                                <div
                                    class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                    <div class="px-1"></div>
                                    <div class="min-w-0 flex items-center gap-2.5">
                                        @if (!empty($ig->avatar))
                                            {{-- Instagram profile-pic URLs are signed and expire (403 "URL signature
                                                 expired"). When that happens, hide the broken image and reveal an
                                                 initials chip instead of a broken-image icon. --}}
                                            <img src="{{ $ig->avatar }}" alt="" referrerpolicy="no-referrer"
                                                onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='grid';}"
                                                class="w-9 h-9 rounded-lg object-cover shrink-0 bg-paper-100">
                                            <span style="display:none"
                                                class="w-9 h-9 rounded-lg place-items-center shrink-0 bg-paper-100 text-ink-700 text-[12px] font-semibold">{{ strtoupper(mb_substr(ltrim((string) ($ig->name ?: $ig->username), '@'), 0, 2)) }}</span>
                                        @else
                                            <span class="w-9 h-9 rounded-lg grid place-items-center shrink-0 bg-paper-100 text-ink-700">
                                                <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.4">
                                                    <rect x="2.2" y="2.2" width="11.6" height="11.6" rx="3.4" />
                                                    <circle cx="8" cy="8" r="2.9" />
                                                    <circle cx="11.3" cy="4.7" r="0.7" fill="currentColor" stroke="none" />
                                                </svg>
                                            </span>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="font-semibold text-ink-900 text-[12.5px] truncate">
                                                {{ $ig->name ?: $igHandle }}</div>
                                            <div class="text-[10.5px] text-ink-500 font-mono truncate flex items-center gap-1">
                                                <svg viewBox="0 0 24 24" class="w-3 h-3 shrink-0" fill="none" stroke="url(#igGrad)" stroke-width="2"><defs><linearGradient id="igGrad" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#F58529"/><stop offset=".5" stop-color="#DD2A7B"/><stop offset="1" stop-color="#8134AF"/></linearGradient></defs><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="#DD2A7B" stroke="none"/></svg>
                                                {{ __('Instagram') }}</div>
                                        </div>
                                    </div>
                                    <div class="font-mono text-[11.5px] text-ink-700 truncate">{{ $igHandle }}</div>
                                    <div class="text-[12px] text-ink-500 truncate">—</div>
                                    <div class="min-w-0">
                                        <div class="font-mono text-[11.5px] text-ink-500 truncate">
                                            {{ $ig->synced_at ? $ig->synced_at->diffForHumans() : __('live') }}</div>
                                    </div>
                                    <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                    <div>
                                        @if ($igLive)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span
                                                    class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-paper-100 text-ink-600"><span
                                                    class="w-1.5 h-1.5 rounded-full bg-paper-300"></span>{{ __('Needs re-auth') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                        @if (!empty($ig->native))
                                            {{-- Native add-on account — actions hit the local /instagram/* routes. --}}
                                            {{-- Health — webhook diagnostics + inbound status + Fix inbound. --}}
                                            <a href="{{ url('/instagram/' . $ig->id . '/health') }}"
                                                class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                title="{{ __('Health & webhook status') }}">
                                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 8h3l1.5-4 3 8 1.5-4h3" /></svg>
                                            </a>
                                            {{-- Refresh — re-pull the account profile from the Graph API. --}}
                                            <form method="POST" action="{{ url('/instagram/' . $ig->id . '/refresh') }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Refresh profile') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </button>
                                            </form>
                                            {{-- Disconnect — remove the local account. --}}
                                            <form method="POST" action="{{ url('/instagram/' . $ig->id) }}"
                                                class="inline" data-confirm="{{ __('Disconnect this Instagram account?') }}">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                    title="{{ __('Disconnect') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5" /></svg>
                                                </button>
                                            </form>
                                        @else
                                            {{-- Manage — open the account on the Instaflow app (new tab). --}}
                                            @if (!empty($instaflowUrl))
                                                <a href="{{ rtrim($instaflowUrl, '/') . '/instagram' }}" target="_blank" rel="noopener"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Manage on :igbrand', ['igbrand' => ig_brand_name()]) }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 3.5H4A1.5 1.5 0 0 0 2.5 5v7A1.5 1.5 0 0 0 4 13.5h7A1.5 1.5 0 0 0 12.5 12V9.5M9 3.5h4v4M13 3.5 7 9.5" /></svg>
                                                </a>
                                            @endif
                                            {{-- Refresh — re-sync the mirror's profile from Instaflow. --}}
                                            <form method="POST" action="{{ url('/devices/instagram/link') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="instaflow_account_id" value="{{ $ig->instaflow_account_id }}">
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Refresh profile from :igbrand', ['igbrand' => ig_brand_name()]) }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </button>
                                            </form>
                                            {{-- Disconnect — drop this workspace's link AND, when it's the last
                                                 account, purge the mirrored inbox so no Instagram data lingers. --}}
                                            <form method="POST" action="{{ url('/devices/instagram/' . $ig->id . '/unlink') }}"
                                                class="inline" data-confirm="{{ __('Disconnect this Instagram account? Its conversations and inbox data will be removed, and Instagram will disappear from your dashboard, inbox, flows, templates and auto-replies until you connect an account again. The account itself stays on :igbrand — you can re-link it later.', ['igbrand' => ig_brand_name()]) }}">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                    title="{{ __('Unlink') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5" /></svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            {{-- Facebook Pages (core channel). Woven into the SAME table as
 rows — exactly like the Instagram accounts above — instead of a separate
 card. Rendered whenever the workspace has connected Pages, independent of the
 WA engine count. Actions hit the local /facebook/* routes. The device cell
 uses the Facebook-blue glyph; the "Mobile number" column shows @username or
 the Page category. Needs: $hasFacebook, $facebookPages. --}}
                            @if (! empty($hasFacebook))
                                @foreach (($facebookPages ?? collect()) as $fp)
                                    @php
                                        $fbLive = $fp->isLive();
                                        $fbSub  = $fp->username
                                            ? '@' . ltrim((string) $fp->username, '@')
                                            : ($fp->category ?: __('Facebook Page'));
                                    @endphp
                                    <div
                                        class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                        <div class="px-1"></div>
                                        <div class="min-w-0 flex items-center gap-2.5">
                                            @if ($fp->picture_url)
                                                {{-- Page picture URLs are signed and can expire; on error hide the
                                                     broken image and reveal the Facebook glyph chip instead. --}}
                                                <img src="{{ $fp->picture_url }}" alt="" referrerpolicy="no-referrer"
                                                    onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='grid';}"
                                                    class="w-9 h-9 rounded-lg object-cover shrink-0 bg-paper-100">
                                                <span style="display:none;background:#1877F2"
                                                    class="w-9 h-9 rounded-lg place-items-center shrink-0">
                                                    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                                                </span>
                                            @else
                                                <span class="w-9 h-9 rounded-lg grid place-items-center shrink-0" style="background:#1877F2">
                                                    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                                                </span>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="font-semibold text-ink-900 text-[12.5px] truncate">
                                                    {{ $fp->name ?: $fp->page_id }}</div>
                                                <div class="text-[10.5px] text-ink-500 font-mono truncate flex items-center gap-1">
                                                    <svg viewBox="0 0 24 24" class="w-3 h-3 shrink-0" fill="#1877F2"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12Z"/></svg>
                                                    {{ __('Facebook') }}</div>
                                            </div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-700 truncate">{{ $fbSub }}</div>
                                        <div class="text-[12px] text-ink-500 truncate">—</div>
                                        <div class="min-w-0">
                                            <div class="font-mono text-[11.5px] text-ink-500 truncate">
                                                {{ $fp->fan_count ? number_format($fp->fan_count) . ' ' . __('followers') : __('live') }}</div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                        <div>
                                            @if ($fbLive)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Live') }}</span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-accent-amber/15 text-[#7B5A14] border border-accent-amber/40"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('Reconnect') }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                            {{-- Reconnect — only when the Page token has expired / disconnected. --}}
                                            @unless ($fbLive)
                                                <a href="{{ route('facebook.connect') }}"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Reconnect') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </a>
                                            @endunless
                                            {{-- Refresh — re-pull the Page profile from the Graph API. --}}
                                            <form method="POST" action="{{ route('facebook.refresh', $fp->id) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Refresh profile') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </button>
                                            </form>
                                            {{-- Remove — disconnect this Page (its inbox threads stay). --}}
                                            <form method="POST" action="{{ route('facebook.disconnect', $fp->id) }}"
                                                class="inline" data-confirm="{{ __('Disconnect this Facebook Page? Its inbox threads stay, but sending stops until you reconnect.') }}">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                    title="{{ __('Remove') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5" /></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            {{-- SMS numbers (Twilio / MSG91) — same table, own rows, like
 Facebook/Instagram/TikTok. Actions go to /sms. Needs: $smsSenders. --}}
                            @foreach (($smsSenders ?? collect()) as $sm)
                                @php
                                    $smMeta = is_array($sm->meta_json) ? $sm->meta_json : [];
                                    $smProv = strtolower($smMeta['sms_provider'] ?? 'twilio');
                                    $smLive = $sm->status === \App\Models\WaProviderConfig::STATUS_CONNECTED;
                                @endphp
                                <div
                                    class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                    <div class="px-1"></div>
                                    <div class="min-w-0 flex items-center gap-2.5">
                                        <span class="w-9 h-9 rounded-lg grid place-items-center shrink-0 bg-wa-deep text-paper-0">
                                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4.5h12v7H8l-3 2.5V11.5H2z" /></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-ink-900 text-[12.5px] truncate">{{ $sm->display_label ?: $sm->phone_number }}</div>
                                            <div class="text-[10.5px] text-ink-500 font-mono truncate flex items-center gap-1">
                                                <svg viewBox="0 0 16 16" class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 4.5h12v7H8l-3 2.5V11.5H2z" /></svg>
                                                {{ $smProv === 'msg91' ? 'SMS · MSG91' : 'SMS · Twilio' }}</div>
                                        </div>
                                    </div>
                                    <div class="font-mono text-[11.5px] text-ink-700 truncate">{{ $sm->phone_number }}</div>
                                    <div class="text-[12px] text-ink-500 truncate">—</div>
                                    <div class="min-w-0"><div class="font-mono text-[11.5px] text-ink-500 truncate">{{ __('live') }}</div></div>
                                    <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                    <div>
                                        @if ($smLive)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-paper-100 text-ink-600"><span class="w-1.5 h-1.5 rounded-full bg-paper-300"></span>{{ __('Inactive') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                        <a href="{{ url('/sms') }}" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition" title="{{ __('Manage SMS') }}">
                                            <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 4.5h12v7H8l-3 2.5V11.5H2z" /></svg>
                                        </a>
                                        <form method="POST" action="{{ url('/sms/' . $sm->id) }}" class="inline" data-confirm="{{ __('Remove this SMS number?') }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-accent-coral/10 text-accent-coral transition" title="{{ __('Remove') }}">
                                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 4h10M6.5 4V2.5h3V4M5 4l.5 9h5l.5-9" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach

                            {{-- Linked email mailboxes (via the connected MailTrixy install).
 Same table, own rows, like SMS/Facebook/Instagram/TikTok — the mailbox lives on
 MailTrixy and this is the workspace's mirror row, so "Refresh" re-pulls its
 details and "Unlink" only drops the mirror (the mailbox itself is untouched).
 Needs: $emailAccounts (computed in index.blade.php). --}}
                            @foreach (($emailAccounts ?? collect()) as $ea)
                                @php
                                    $eaLive  = strtolower((string) $ea->status) === 'connected';
                                    $eaProv  = strtolower((string) $ea->provider);
                                    $eaLabel = $eaProv === '' ? __('Email') : __('Email') . ' · ' . ucfirst($eaProv);
                                @endphp
                                <div
                                    class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                    <div class="px-1"></div>
                                    <div class="min-w-0 flex items-center gap-2.5">
                                        <span class="w-9 h-9 rounded-lg grid place-items-center shrink-0 bg-wa-deep text-paper-0">
                                            <svg viewBox="0 0 16 16" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12v8H2z" /><path d="m2 4.5 6 4.5 6-4.5" /></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-ink-900 text-[12.5px] truncate">{{ $ea->name ?: $ea->email }}</div>
                                            <div class="text-[10.5px] text-ink-500 font-mono truncate flex items-center gap-1">
                                                <svg viewBox="0 0 16 16" class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 4h12v8H2z" /><path d="m2 4.5 6 4.5 6-4.5" /></svg>
                                                {{ $eaLabel }}</div>
                                        </div>
                                    </div>
                                    <div class="font-mono text-[11.5px] text-ink-700 truncate" title="{{ $ea->email }}">{{ $ea->email }}</div>
                                    <div class="text-[12px] text-ink-500 truncate">—</div>
                                    <div class="min-w-0"><div class="font-mono text-[11.5px] text-ink-500 truncate">{{ $ea->synced_at ? $ea->synced_at->diffForHumans() : __('live') }}</div></div>
                                    <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                    <div>
                                        @if ($eaLive)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Connected') }}</span>
                                        @else
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-paper-100 text-ink-600"><span class="w-1.5 h-1.5 rounded-full bg-paper-300"></span>{{ __('Inactive') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                        {{-- Sync pulls this mailbox's mail from the email server. The
                                             live push only announces NEW messages and gives up after 8s
                                             with no retry, so this is how history arrives and how a
                                             dropped message is recovered. Safe to press twice — import
                                             dedupes on the remote message id. --}}
                                        <form method="POST" action="{{ url('/devices/email/' . $ea->id . '/sync') }}" class="inline">
                                            @csrf
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                title="{{ __('Sync — fetch mail for this account') }}">
                                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9" /><path d="M13.5 2.5V5H11" /></svg>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ url('/devices/email/' . $ea->id . '/unlink') }}" class="inline"
                                            data-confirm="{{ __('Unlink this email account from the workspace? Its messages stop arriving here. The mailbox itself is not deleted.') }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="w-8 h-8 rounded-lg grid place-items-center hover:bg-accent-coral/10 text-accent-coral transition" title="{{ __('Unlink') }}">
                                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 4h10M6.5 4V2.5h3V4M5 4l.5 9h5l.5-9" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach

                            {{-- TikTok accounts (core channel). Woven into the SAME table as
 rows — exactly like Facebook/Instagram — when the workspace has connected
 accounts. Actions hit the local /tiktok/* routes. Needs: $hasTiktok, $tiktokAccounts. --}}
                            @if (! empty($hasTiktok))
                                @foreach (($tiktokAccounts ?? collect()) as $ta)
                                    @php
                                        $ttLive = $ta->isLive();
                                        $ttSub  = $ta->username ? '@' . ltrim((string) $ta->username, '@') : __('TikTok account');
                                    @endphp
                                    <div
                                        class="min-w-[1160px] grid grid-cols-[40px_minmax(200px,1.4fr)_150px_140px_120px_90px_140px_220px] items-center gap-3 px-4 py-3 border-b border-paper-200 last:border-0 hover:bg-paper-50/60">
                                        <div class="px-1"></div>
                                        <div class="min-w-0 flex items-center gap-2.5">
                                            @if ($ta->avatar_url)
                                                <img src="{{ $ta->avatar_url }}" alt="" referrerpolicy="no-referrer"
                                                    onerror="this.style.display='none'; if(this.nextElementSibling){this.nextElementSibling.style.display='grid';}"
                                                    class="w-9 h-9 rounded-lg object-cover shrink-0 bg-paper-100">
                                                <span style="display:none;background:#010101"
                                                    class="w-9 h-9 rounded-lg place-items-center shrink-0">
                                                    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/></svg>
                                                </span>
                                            @else
                                                <span class="w-9 h-9 rounded-lg grid place-items-center shrink-0" style="background:#010101">
                                                    <svg viewBox="0 0 24 24" class="w-4 h-4" fill="#fff"><path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/></svg>
                                                </span>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="font-semibold text-ink-900 text-[12.5px] truncate">
                                                    {{ $ta->display_name ?: $ttSub }}</div>
                                                <div class="text-[10.5px] text-ink-500 font-mono truncate flex items-center gap-1">
                                                    <svg viewBox="0 0 24 24" class="w-3 h-3 shrink-0" fill="currentColor"><path d="M16.6 5.8a4.3 4.3 0 0 1-2.6-3.8h-3.1v12.4a2.6 2.6 0 1 1-2.6-2.6c.27 0 .53.04.78.12V8.7a5.7 5.7 0 1 0 4.9 5.65V8.4a7.3 7.3 0 0 0 4.3 1.38V6.66a4.3 4.3 0 0 1-1.68-.86Z"/></svg>
                                                    {{ __('TikTok') }}</div>
                                            </div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-700 truncate">{{ $ttSub }}</div>
                                        <div class="text-[12px] text-ink-500 truncate">—</div>
                                        <div class="min-w-0">
                                            <div class="font-mono text-[11.5px] text-ink-500 truncate">
                                                {{ $ta->follower_count ? number_format($ta->follower_count) . ' ' . __('followers') : __('live') }}</div>
                                        </div>
                                        <div class="font-mono text-[11.5px] text-ink-500">—</div>
                                        <div>
                                            @if ($ttLive)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-wa-mint text-wa-deep"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-wa-green"></span>{{ __('Live') }}</span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10.5px] font-mono bg-accent-amber/15 text-[#7B5A14] border border-accent-amber/40"><span
                                                        class="w-1.5 h-1.5 rounded-full bg-accent-amber"></span>{{ __('Reconnect') }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-0.5 justify-end whitespace-nowrap">
                                            <a href="{{ url('/tiktok/accounts') }}"
                                                class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                title="{{ __('Manage TikTok') }}">
                                                <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 3.5H4A1.5 1.5 0 0 0 2.5 5v7A1.5 1.5 0 0 0 4 13.5h7A1.5 1.5 0 0 0 12.5 12V9.5M9 3.5h4v4M13 3.5 7 9.5" /></svg>
                                            </a>
                                            @unless ($ttLive)
                                                <a href="{{ route('user.tiktok.connect') }}"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Reconnect') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </a>
                                            @endunless
                                            <form method="POST" action="{{ route('user.tiktok.refresh', $ta->id) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center hover:bg-paper-100 text-ink-500 transition"
                                                    title="{{ __('Refresh profile') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 3.5v3h-3M2.5 12.5v-3h3" /><path d="M12.4 6a4.5 4.5 0 0 0-8.2-.8M3.6 10a4.5 4.5 0 0 0 8.2.8" /></svg>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('user.tiktok.disconnect', $ta->id) }}"
                                                class="inline" data-confirm="{{ __('Disconnect this TikTok account? Its inbox threads stay, but sending stops until you reconnect.') }}">
                                                @csrf @method('DELETE')
                                                <button type="submit"
                                                    class="w-8 h-8 rounded-lg grid place-items-center text-accent-coral hover:bg-accent-coral/10 transition"
                                                    title="{{ __('Disconnect') }}">
                                                    <svg viewBox="0 0 16 16" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4h11M6 4V2.5h4V4M4.3 4l.6 9.5h6.2l.6-9.5" /></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
