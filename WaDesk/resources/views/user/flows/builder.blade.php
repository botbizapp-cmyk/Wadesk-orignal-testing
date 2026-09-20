<x-layouts.user :title="__('Flow Builder')" nav-key="flows" page="user-flows-builder" :hide-header="true">

    @php
        $flowId = isset($flow) && $flow ? $flow->id : null;
        $flowName = isset($flow) && $flow ? $flow->flow_name ?? 'New flow' : 'New flow';
        $flowJson = $flowJson ?? ['flowNodes' => [], 'flowEdges' => []];
        $isPublished = isset($flow) && $flow && $flow->is_published;
        $category = isset($flow) && $flow ? $flow->category ?? '' : '';
        $flowType = $flowType ?? (isset($flow) && $flow ? ($flow->flow_type ?: 'chat') : 'chat');
    @endphp

    <div id="root" data-flow-id="{{ $flowId }}" data-flow-name="{{ $flowName }}"
        data-flow-category="{{ $category }}" data-flow-published="{{ $isPublished ? '1' : '0' }}"
        data-flow-type="{{ $flowType }}"
        {{-- Whether the Instagram channel is offered at all. TRUE when either
             Instagram system is present in THIS workspace: the native addon
             extension (InstagramAccount) OR an Instaflow-bridge account
             connected via the one-click "Connect Instagram" button
             (WorkspaceIgAccount) — the latter is how most deployments run IG,
             and gating only on the addon meant a user with a connected IG
             account still saw WhatsApp-only in the builder. Without ANY
             Instagram present the channel stays hidden so you can't draw a flow
             that could never run. Plan entitlement is checked separately by the
             routes; this is purely "can this workspace run an Instagram flow". --}}
        @php
            $wsIdForFlow = (int) (auth()->user()->current_workspace_id ?? 0);
            // Instaflow-connected IG accounts, embedded straight into the page.
            // The builder ALSO fetches these from /flows/api/picker, but a stale
            // browser-cached copy of that GET kept the trigger's account dropdown
            // empty even after connecting — so we hand the accounts to the JS in
            // the HTML itself (data-ig-accounts) as the guaranteed source. Same
            // {key,id,label,username} shape the picker returns.
            $igAccountsForFlow = collect();
            if ($wsIdForFlow && class_exists(\App\Models\WorkspaceIgAccount::class)) {
                try {
                    $igAccountsForFlow = \App\Models\WorkspaceIgAccount::where('workspace_id', $wsIdForFlow)
                        ->orderBy('username')->get()
                        ->map(fn ($a) => [
                            'key'      => 'instagram:' . $a->id,
                            'id'       => (int) $a->id,
                            'label'    => '@' . ($a->username ?: ('account ' . $a->id)),
                            'username' => (string) $a->username,
                        ])->values();
                } catch (\Throwable $e) { $igAccountsForFlow = collect(); }
            }
            // Offer the Instagram channel ONLY when the workspace has an
            // actually CONNECTED account it could run a flow on — a native
            // addon account (status=connected) OR an Instaflow-bridge account.
            // Gating on Extension::enabled('instagram') alone was wrong: with the
            // addon enabled but ZERO accounts connected, Instagram still showed
            // in the Channel picker even though no flow could ever run. Guarded
            // for the addon being absent / mid-install (class + table checks) so
            // this never 500s the builder.
            $igNativeConnected = false;
            if ($wsIdForFlow
                && class_exists(\App\Models\InstagramAccount::class)
                && \Illuminate\Support\Facades\Schema::hasTable('instagram_accounts')) {
                try {
                    $igNativeConnected = \App\Models\InstagramAccount::where('workspace_id', $wsIdForFlow)
                        ->where('status', 'connected')->exists();
                } catch (\Throwable $e) { $igNativeConnected = false; }
            }
            $igAvailable = $igNativeConnected || $igAccountsForFlow->isNotEmpty();

            // Facebook Pages channel. Offered ONLY when the admin enabled the
            // channel AND this workspace has a CONNECTED Page to run a flow on —
            // same "no dead channels" rule as Instagram above. Pages are embedded
            // in the HTML (data-fb-pages) as the guaranteed source, same
            // {key:'facebook:<rowId>', id, label} shape the picker returns. The
            // key binds to the FacebookPage DB row id, which FacebookIngestService
            // ::resolveFbKeywordFlow matches on trigger_device_id at runtime.
            $fbPagesForFlow = collect();
            $fbEnabled = false;
            if ($wsIdForFlow
                && (bool) \App\Models\SystemSetting::get('facebook_enabled', false)
                && class_exists(\App\Models\FacebookPage::class)
                && \Illuminate\Support\Facades\Schema::hasTable('facebook_pages')) {
                try {
                    $fbPagesForFlow = \App\Models\FacebookPage::where('workspace_id', $wsIdForFlow)
                        ->where('status', 'connected')->orderBy('name')->get()
                        ->map(fn ($p) => [
                            'key'   => 'facebook:' . $p->id,
                            'id'    => (int) $p->id,
                            'label' => (string) ($p->name ?: ('Page ' . $p->page_id)),
                        ])->values();
                    $fbEnabled = $fbPagesForFlow->isNotEmpty();
                } catch (\Throwable $e) { $fbPagesForFlow = collect(); $fbEnabled = false; }
            }

            // TikTok channel — same "no dead channels" rule: offered only when the
            // admin enabled TikTok AND this workspace has a connected account. The
            // key binds to the TiktokAccount row id, which
            // TiktokIngestService::resolveTiktokKeywordFlow matches at runtime.
            $ttAccountsForFlow = collect();
            $ttEnabled = false;
            if ($wsIdForFlow
                && (bool) \App\Models\SystemSetting::get('tiktok_enabled', false)
                && class_exists(\App\Models\TiktokAccount::class)
                && \Illuminate\Support\Facades\Schema::hasTable('tiktok_accounts')) {
                try {
                    $ttAccountsForFlow = \App\Models\TiktokAccount::where('workspace_id', $wsIdForFlow)
                        ->where('status', 'connected')->orderBy('display_name')->get()
                        ->map(fn ($a) => [
                            'key'   => 'tiktok:' . $a->id,
                            'id'    => (int) $a->id,
                            'label' => (string) ($a->display_name ?: ($a->username ? '@' . $a->username : ('Account ' . $a->id))),
                        ])->values();
                    $ttEnabled = $ttAccountsForFlow->isNotEmpty();
                } catch (\Throwable $e) { $ttAccountsForFlow = collect(); $ttEnabled = false; }
            }

            // Telegram channel — offered when the plan allows AND a bot is
            // connected. Binds to the telegram_bots row id.
            $tgBotsForFlow = collect();
            $tgEnabled = false;
            if ($wsIdForFlow
                && class_exists(\App\Models\TelegramBot::class)
                && \Illuminate\Support\Facades\Schema::hasTable('telegram_bots')) {
                try {
                    $tgBotsForFlow = \App\Models\TelegramBot::where('workspace_id', $wsIdForFlow)
                        ->where('active', true)->orderBy('bot_name')->get()
                        ->map(fn ($b) => [
                            'key'   => 'telegram:' . $b->id,
                            'id'    => (int) $b->id,
                            'label' => (string) ($b->bot_name ?: ($b->bot_username ? '@' . $b->bot_username : ('Bot ' . $b->id))),
                        ])->values();
                    $tgEnabled = $tgBotsForFlow->isNotEmpty();
                } catch (\Throwable $e) { $tgBotsForFlow = collect(); $tgEnabled = false; }
            }

            // LINE / WeChat / Viber — offered when a channel is connected. Each
            // binds the flow to a specific channel row id (its token sends the
            // reply), exactly like Telegram above. The flow runtime resolver
            // matches on flow_type + trigger_device_id = this id.
            $lineChansForFlow = collect(); $lineEnabled = false;
            $wechatChansForFlow = collect(); $wechatEnabled = false;
            $viberChansForFlow = collect(); $viberEnabled = false;
            if ($wsIdForFlow) {
                try {
                    if (class_exists(\App\Models\LineChannel::class) && \Illuminate\Support\Facades\Schema::hasTable('line_channels')) {
                        $lineChansForFlow = \App\Models\LineChannel::where('workspace_id', $wsIdForFlow)->where('active', true)->orderBy('display_name')->get()
                            ->map(fn ($c) => ['key' => 'line:' . $c->id, 'id' => (int) $c->id, 'label' => (string) ($c->display_name ?: ($c->basic_id ?: ('LINE ' . $c->id)))])->values();
                        $lineEnabled = $lineChansForFlow->isNotEmpty();
                    }
                } catch (\Throwable $e) { $lineChansForFlow = collect(); $lineEnabled = false; }
                try {
                    if (class_exists(\App\Models\WeChatChannel::class) && \Illuminate\Support\Facades\Schema::hasTable('wechat_channels')) {
                        $wechatChansForFlow = \App\Models\WeChatChannel::where('workspace_id', $wsIdForFlow)->where('active', true)->orderBy('account_name')->get()
                            ->map(fn ($c) => ['key' => 'wechat:' . $c->id, 'id' => (int) $c->id, 'label' => (string) ($c->account_name ?: ($c->wx_id ?: ('WeChat ' . $c->id)))])->values();
                        $wechatEnabled = $wechatChansForFlow->isNotEmpty();
                    }
                } catch (\Throwable $e) { $wechatChansForFlow = collect(); $wechatEnabled = false; }
                try {
                    if (class_exists(\App\Models\ViberChannel::class) && \Illuminate\Support\Facades\Schema::hasTable('viber_channels')) {
                        $viberChansForFlow = \App\Models\ViberChannel::where('workspace_id', $wsIdForFlow)->where('active', true)->orderBy('bot_name')->get()
                            ->map(fn ($c) => ['key' => 'viber:' . $c->id, 'id' => (int) $c->id, 'label' => (string) ($c->bot_name ?: ($c->bot_uri ?: ('Viber ' . $c->id)))])->values();
                        $viberEnabled = $viberChansForFlow->isNotEmpty();
                    }
                } catch (\Throwable $e) { $viberChansForFlow = collect(); $viberEnabled = false; }
            }

            // Email channel — the mailbox lives on the connected mail platform;
            // this workspace links it as a mirror row (like Instagram). Offered
            // only when the platform toggle is on AND this workspace has a
            // CONNECTED mailbox, so the same "no dead channels" rule holds: an
            // email flow with no mailbox would save, publish and never fire.
            // Same {key:'email:<mirrorRowId>', id, label} shape /flows/api/picker
            // returns; the key binds to the WorkspaceEmailAccount row id, which
            // MailtrixyIngestService::resolveEmailKeywordFlow matches on
            // trigger_device_id at runtime.
            $emailAcctsForFlow = collect();
            $emailEnabled = false;
            if ($wsIdForFlow
                && class_exists(\App\Models\WorkspaceEmailAccount::class)
                && \Illuminate\Support\Facades\Schema::hasTable('workspace_email_accounts')) {
                try {
                    if ((bool) \App\Models\SystemSetting::get('email_enabled', false)
                        && \App\Models\WorkspaceEmailAccount::hasConnected($wsIdForFlow)) {
                        $emailAcctsForFlow = \App\Models\WorkspaceEmailAccount::forWorkspace($wsIdForFlow)
                            ->connected()->orderBy('email')->get()
                            ->map(fn ($a) => [
                                'key'   => 'email:' . $a->id,
                                'id'    => (int) $a->id,
                                'label' => (string) ($a->name ?: ($a->email ?: ('Email ' . $a->id))),
                            ])->values();
                        $emailEnabled = $emailAcctsForFlow->isNotEmpty();
                    }
                } catch (\Throwable $e) { $emailAcctsForFlow = collect(); $emailEnabled = false; }
            }
        @endphp
        data-ext-instagram="{{ $igAvailable ? '1' : '0' }}"
        data-ig-accounts="{{ json_encode($igAccountsForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-fb="{{ $fbEnabled ? '1' : '0' }}"
        data-fb-pages="{{ json_encode($fbPagesForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-tt="{{ $ttEnabled ? '1' : '0' }}"
        data-tt-accounts="{{ json_encode($ttAccountsForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-tg="{{ $tgEnabled ? '1' : '0' }}"
        data-tg-bots="{{ json_encode($tgBotsForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-line="{{ $lineEnabled ? '1' : '0' }}"
        data-line-channels="{{ json_encode($lineChansForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-wechat="{{ $wechatEnabled ? '1' : '0' }}"
        data-wechat-channels="{{ json_encode($wechatChansForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-viber="{{ $viberEnabled ? '1' : '0' }}"
        data-viber-channels="{{ json_encode($viberChansForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-email="{{ $emailEnabled ? '1' : '0' }}"
        data-email-accounts="{{ json_encode($emailAcctsForFlow, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}"
        data-flow-json="{{ json_encode($flowJson, JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) }}">
        <div class="h-screen w-screen grid place-items-center">
            <div class="text-center">
                <div class="font-serif text-[18px] text-ink-700">{{ __('Loading flow builder...') }}</div>
            </div>
        </div>
    </div>

</x-layouts.user>
