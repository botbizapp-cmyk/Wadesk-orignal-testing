<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FacebookPage;
use App\Models\Flow;
use App\Models\InboxMessage;
use App\Services\Facebook\FacebookPageClient;
use App\Services\Flow\FlowEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Laravel callbacks for the ported Facebook Page flow engine that runs on Node.
 * Node runs every send node itself via the Graph API (Messenger Send API /
 * comment replies through FacebookPageClient) and calls back here for two
 * things only:
 *
 *   POST /api/facebook/flow-log   — mirror a flow message into WaDesk's inbox.
 *   POST /api/facebook/flow-node  — resolve a "smart" node (AI / gallery / lead).
 *
 * Both are secret-guarded with the shared X-Node-Token (node_token()). This is
 * the Facebook clone of InstagramFlowNodeController: where the Instagram version
 * resolves an InstagramAccount it resolves a FacebookPage by page_id, where it
 * mirrors into the channel='instagram' inbox it writes channel='facebook'
 * Conversations + InboxMessages (raw_jid 'fb:<pageId>:<psid>', matching
 * FacebookIngestService), and where it uses the IG send helpers it uses
 * FacebookPageClient (sendMessage / replyComment).
 */
class FacebookFlowNodeController extends Controller
{
    private function unauthorized(Request $request): bool
    {
        $expected = (string) node_token();
        return $expected === '' || ! hash_equals($expected, (string) $request->header('X-Node-Token', ''));
    }

    /** POST /api/facebook/flow-log — mirror an outbound/inbound flow message into the inbox. */
    public function log(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) return response()->json(['ok' => false], 401);

        // Node identifies the Page by its Meta page_id (same key the webhook
        // uses); resolve it to the connected FacebookPage row.
        $pageId    = (string) $request->input('pageId', $request->input('page_id', ''));
        $psid      = (string) $request->input('psid', '');
        $body      = (string) $request->input('body', '');
        $direction = $request->input('direction') === 'in' ? 'in' : 'out';
        $page      = $pageId !== '' ? FacebookPage::findByPageId($pageId) : null;

        // Media the flow attached alongside (or instead of) the text.
        $mediaType = ($t = trim((string) $request->input('mediaType', $request->input('media_type', '')))) !== '' ? $t : null;
        $mediaPath = ($p = trim((string) $request->input('mediaPath', $request->input('media_path', '')))) !== '' ? $p : null;

        // Interactive buttons the flow sent alongside the text (quick replies /
        // generic-template buttons). Passing them through makes the inbox render
        // the same button card the customer saw, not just the prompt text.
        $buttons = $request->input('buttons');
        $buttons = is_array($buttons) ? $buttons : null;

        if ($page && $psid !== '' && ($body !== '' || $mediaPath !== null)) {
            $wsId = (int) $page->workspace_id;

            // One thread per DM sender (PSID) — identical key shape to
            // FacebookIngestService so the flow's messages land in the SAME
            // conversation as live Messenger DMs.
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'facebook', 'raw_jid' => 'fb:'.$pageId.':'.$psid],
                [
                    'title'           => $psid,
                    'provider'        => 'facebook',
                    'origin'          => 'facebook',
                    'status'          => 'pending',
                    'inbox_status'    => 'open',
                    'last_message_at' => now(),
                    'contact_digits'  => null,
                ]
            );

            // Resolve the customer's REAL name + avatar once, on thread creation
            // (cached 6h under the same key FacebookIngestService uses) so the
            // conversation shows a friendly name — and a flow message never
            // OVERWRITES an already-good title with the raw PSID.
            if ($conv->wasRecentlyCreated) {
                try {
                    $prof = Cache::remember('fb_sender:'.$page->id.':'.$psid, 21600,
                        fn () => (new FacebookPageClient($page))->getSenderProfile($psid));
                    $name = trim((string) ($prof['name'] ?? '')) ?: trim(((string) ($prof['first_name'] ?? '')).' '.((string) ($prof['last_name'] ?? '')));
                    if ($name !== '') {
                        $conv->forceFill([
                            'title'        => $name,
                            'routing_meta' => array_merge((array) $conv->routing_meta, ['fb_avatar' => (string) ($prof['profile_pic'] ?? '')]),
                        ])->save();
                    }
                } catch (\Throwable $e) {
                    // best effort — fall back to the PSID title
                }
            }

            try {
                $inbox = InboxMessage::create(array_filter([
                    'conversation_id' => $conv->id,
                    'provider'        => 'facebook',
                    'direction'       => $direction,
                    'body'            => $body,
                    'media_type'      => $mediaType,
                    'media_path'      => $mediaPath,
                    // Outbound = Page speaking; inbound = the PSID.
                    'from_number'     => $direction === 'in' ? $psid : null,
                    'status'          => $direction === 'in' ? 'received' : 'sent',
                    'meta'            => ['facebook' => array_filter([
                        'message_id' => (string) $request->input('mid', ''),
                        'psid'       => $psid,
                        'kind'       => 'dm',
                        'buttons'    => $buttons,
                    ], fn ($v) => $v !== null && $v !== '')],
                    'sent_at'         => now(),
                    'delivered_at'    => $direction === 'out' ? now() : null,
                ], fn ($v) => $v !== null));

                $conv->forceFill(array_filter([
                    'preview'         => Str::limit($body !== '' ? $body : '['.($mediaType ?: 'media').']', 120),
                    'last_message_at' => now(),
                    'last_inbound_at' => $direction === 'in' ? now() : null,
                    'unread_count'    => $direction === 'in' ? ((int) $conv->unread_count + 1) : (int) $conv->unread_count,
                ], fn ($v) => $v !== null))->save();
            } catch (\Throwable $e) {
                // Logging must never break the conversation.
                Log::warning('[FB-FLOW-LOG] mirror failed: '.$e->getMessage());
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/facebook/flow-node — resolve a "smart" flow node whose logic
     * needs DB / keys / the Graph API. The Node runtime posts
     * {action, node, vars, workspaceId, pageId, psid, commentId}. We dispatch on
     * `action` (the node type); anything we don't handle returns a shape Node
     * tolerates (empty text = skip) so a flow never dead-ends on an un-wired node.
     */
    public function node(Request $request): JsonResponse
    {
        if ($this->unauthorized($request)) return response()->json(['ok' => false], 401);

        // Node sends the node type as `action`; keep `type` as a fallback.
        $type = (string) ($request->input('action') ?: $request->input('type', ''));

        // Cross-channel handoff (direct mode) — start a WhatsApp flow to a
        // number the Facebook flow captured earlier.
        if ($type === 'fb_to_whatsapp') {
            return $this->handleHandoff($request);
        }

        // AI reply node — the Facebook runtime routes `ai`/`fb_ai` here expecting
        // {reply}. Reuses the SAME provider path the WhatsApp flow AI node uses
        // (AiAgentService::callProvider + AI-Training knowledge base), so a
        // Facebook flow answers from the workspace's trained content + admin keys.
        if ($type === 'ai' || $type === 'fb_ai') {
            return $this->handleAi($request);
        }

        // Webhook node — the runtime routes `webhook` here expecting {vars}. We
        // run the HTTP call server-side (SSRF-guarded) and flatten the JSON
        // response into dotted vars, matching the WhatsApp Node executor so a
        // flow built once behaves the same on every channel.
        if ($type === 'webhook') {
            return $this->handleWebhook($request);
        }

        // Resolve the connected Page for the DB-backed nodes below.
        $pageId = (string) $request->input('pageId', $request->input('page_id', ''));
        $page   = $pageId !== '' ? FacebookPage::findByPageId($pageId) : null;

        try {
            if ($page) {
                switch ($type) {
                    case 'fb_reply_comment':   return $this->handleReplyComment($request, $page);
                    case 'fb_lead':            return $this->handleLead($request, $page);
                    case 'fb_products':
                    case 'fb_gallery':         return $this->handleCarousel($request, $page, $type);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[FB-FLOW-NODE] '.$type.' failed: '.$e->getMessage());
        }

        return response()->json(['ok' => true, 'type' => $type, 'text' => '', 'items' => []]);
    }

    /** Substitute {{var}} placeholders from the flow's variable bag. */
    private function subst(string $s, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? '';
            return is_scalar($v) ? (string) $v : '';
        }, $s);
    }

    /**
     * AI reply node (`ai`/`fb_ai`). Reuses the SAME provider path the WhatsApp
     * flow AI node uses (AiAgentService::callProvider + AI-Training knowledge
     * base) so a Facebook flow answers from the workspace's trained content and
     * admin keys. Returns {reply} — the Node runtime sends it + stores it under
     * the node's `save` var.
     */
    private function handleAi(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $wsId = (int) $request->input('workspaceId', 0);

        $model = trim((string) ($node['model'] ?? $node['aiModel'] ?? '')) ?: 'gpt-4o-mini';
        $ml = strtolower($model);
        $provider = str_starts_with($ml, 'claude') ? 'anthropic'
            : (str_starts_with($ml, 'gemini') ? 'gemini'
            : ((str_starts_with($ml, 'mistral') || str_starts_with($ml, 'ministral') || str_starts_with($ml, 'open-mistral') || str_starts_with($ml, 'open-mixtral')) ? 'mistral'
            : 'openai'));

        $system = trim((string) ($node['system'] ?? $node['systemPrompt'] ?? $node['prompt'] ?? ''))
            ?: 'You are a helpful assistant replying inside a Facebook Messenger conversation. Be concise and friendly.';
        $system = $this->subst($system, $vars);

        // AI-Training knowledge base — stitch the attached assistant's trained
        // sources into the system prompt, exactly like FlowNodeActionsController::aiCall.
        $assistantId = (int) ($node['assistantId'] ?? $node['assistant_id'] ?? $node['knowledgeBaseId'] ?? 0);
        if ($assistantId > 0 && $wsId > 0) {
            try {
                $assistant = \App\Models\AiChatAssistant::where('workspace_id', $wsId)->find($assistantId);
                if ($assistant) {
                    $kb = app(\App\Services\AiChat\AiChatService::class)->contextFor($assistant);
                    if (trim($kb) !== '') {
                        $system .= "\n\n--- Knowledge base ---\n".$kb."\n--- End knowledge base ---";
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[FB-FLOW-NODE] ai knowledge-base inject failed: '.$e->getMessage());
            }
        }

        // The customer's message drives the reply: an explicit node prompt (with
        // {{vars}}) wins, else the last inbound text carried in the var bag.
        $userPrompt = $this->subst((string) ($node['userPrompt'] ?? $node['user_prompt'] ?? ''), $vars);
        if (trim($userPrompt) === '') {
            $userPrompt = (string) ($vars['text'] ?? $vars['user_message'] ?? $vars['last_message'] ?? '');
        }

        $reply = (string) (app(\App\Services\AiAgentService::class)->callProvider(
            provider:     $provider,
            model:        $model,
            workspaceId:  $wsId,
            systemPrompt: $system,
            userPrompt:   $userPrompt,
            maxTokens:    (int) ($node['maxTokens'] ?? $node['max_tokens'] ?? 350),
            temperature:  (float) ($node['temperature'] ?? 0.7),
            jsonMode:     false,
        ) ?? '');

        $out = ['ok' => true, 'type' => 'ai', 'reply' => $reply];
        $save = trim((string) ($node['save'] ?? ''));
        if ($save !== '') {
            $out['vars'] = [$save => $reply];
        }

        return response()->json($out);
    }

    /**
     * Webhook node. The Facebook runtime routes `webhook` here (unlike the
     * WhatsApp runtime, which runs it in Node) expecting {vars}. We make the
     * HTTP call server-side behind an SSRF guard and flatten the JSON response
     * into dotted vars — same field names + behaviour as the Node executor
     * (method/url/contentType/variable/body/headers) so a flow built once works
     * identically on every channel.
     */
    private function handleWebhook(Request $request): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);

        $url = trim($this->subst((string) ($node['url'] ?? ''), $vars));
        if ($url === '' || ! $this->isPublicHttpUrl($url)) {
            Log::warning('[FB-FLOW-NODE] webhook skipped — empty or unsafe url', ['url' => $url]);
            return response()->json(['ok' => false, 'type' => 'webhook', 'vars' => []]);
        }

        $method = strtoupper(trim((string) ($node['method'] ?? 'POST')));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            $method = 'POST';
        }
        $contentType = (string) ($node['contentType'] ?? 'application/json');
        $saveAs = trim((string) ($node['variable'] ?? 'response')) ?: 'response';

        $headers = ['Content-Type' => $contentType];
        foreach ((array) ($node['headers'] ?? []) as $h) {
            $k = trim((string) ($h['key'] ?? ''));
            if ($k !== '') {
                $headers[$k] = $this->subst((string) ($h['value'] ?? ''), $vars);
            }
        }
        $body = $this->subst((string) ($node['body'] ?? ''), $vars);

        $outVars = [];
        try {
            // withoutRedirecting() fails closed on 3xx so a vetted public host
            // can't 302 us onto an internal one. 15s timeout.
            $http = Http::withHeaders($headers)->timeout(15)->withoutRedirecting();
            if ($method === 'GET' || $method === 'HEAD') {
                $resp = $http->send($method, $url);
            } elseif (str_contains($contentType, 'json')) {
                $decoded = null;
                if ($body !== '') {
                    $decoded = json_decode($body, true);
                }
                $resp = $http->withBody(
                    is_array($decoded) ? json_encode($decoded) : $body,
                    $contentType
                )->send($method, $url);
            } else {
                $resp = $http->withBody($body, $contentType)->send($method, $url);
            }

            $json = $resp->json();
            // Whole body under {{saveAs}} + every field flattened to dotted keys
            // so {{saveAs.field}} resolves in later message/AI nodes.
            $outVars[$saveAs] = is_array($json) ? json_encode($json) : (string) $resp->body();
            if (is_array($json)) {
                $this->flattenInto($outVars, $saveAs, $json);
            }
        } catch (\Throwable $e) {
            Log::warning('[FB-FLOW-NODE] webhook request failed: '.mb_substr($e->getMessage(), 0, 200), ['url' => $url]);
        }

        return response()->json(['ok' => true, 'type' => 'webhook', 'vars' => $outVars]);
    }

    /** Flatten a JSON tree into dotted keys under a prefix (a.b.c => "a.b.c"). */
    private function flattenInto(array &$bag, string $prefix, array $data): void
    {
        foreach ($data as $k => $v) {
            $key = $prefix.'.'.$k;
            if (is_array($v)) {
                $this->flattenInto($bag, $key, $v);
            } elseif (is_scalar($v) || $v === null) {
                $bag[$key] = $v === null ? '' : (string) $v;
            }
        }
    }

    /**
     * SSRF guard: allow only http/https to a host that resolves to a PUBLIC IP.
     * Blocks loopback / private / link-local / reserved ranges so an operator
     * can't point a webhook at internal infrastructure.
     */
    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        // Resolve every A record; reject if ANY is non-public.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (! empty($r['ip'])) $ips[] = $r['ip'];
                if (! empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
            if ($ips === []) {
                $resolved = gethostbyname($host);
                if ($resolved && $resolved !== $host) $ips[] = $resolved;
            }
        }
        if ($ips === []) {
            return false; // couldn't resolve → refuse
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // private / reserved / loopback → block
            }
        }

        return true;
    }

    /** fb_reply_comment — post a PUBLIC reply under the comment that triggered the flow. */
    private function handleReplyComment(Request $request, FacebookPage $page): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $objectId = (string) ($request->input('commentId')
            ?: ($vars['comment_id'] ?? '')
            ?: ($vars['post_id'] ?? ''));
        $text = $this->subst((string) ($node['text'] ?? $node['message'] ?? ''), $vars);

        // Plan gate — publicly replying under a comment is the facebook_comments
        // capability. Off by default → skip the reply (the flow still continues;
        // nothing is posted). Makes the facebook_comments plan toggle do something.
        $fbCommentsOk = \App\Services\PlanLimitGuard::hasFeature(
            \App\Models\Workspace::find($page->workspace_id),
            'facebook_comments'
        );
        if ($fbCommentsOk && $objectId !== '' && trim($text) !== '') {
            (new FacebookPageClient($page))->replyComment($objectId, $text);
        }

        return response()->json(['ok' => true, 'type' => 'fb_reply_comment']);
    }

    /** fb_lead — map captured vars into a saved Contact (+ optional Deal), then DM the ack. */
    private function handleLead(Request $request, FacebookPage $page): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $psid = (string) $request->input('psid', '');
        $wsId = (int) $page->workspace_id;

        $name  = trim((string) ($vars[$node['nameVar']  ?? 'lead_name']  ?? ''));
        $email = trim((string) ($vars[$node['emailVar'] ?? 'lead_email'] ?? ''));
        $phone = trim((string) ($vars[$node['phoneVar'] ?? 'lead_phone'] ?? ''));
        $notes = trim((string) ($vars[$node['notesVar'] ?? ''] ?? ''));

        // Reuse the same PSID-keyed digits the handoff node uses so the lead ties
        // back to this Messenger contact; fall back to the phone they typed.
        $digits  = preg_replace('/\D+/', '', $phone !== '' ? $phone : $psid);
        $hash    = Contact::hashPhone('', $digits ?: $psid);
        $contact = Contact::query()->where('workspace_id', $wsId)->where('mobile_hash', $hash)->first();
        if (! $contact) {
            $contact = new Contact();
            $contact->workspace_id = $wsId;
            $contact->user_id      = (int) $page->user_id;
            $contact->country_code = '';
            $contact->mobile       = $digits ?: $psid;
        }
        if ($name !== '')  $contact->name  = $name;
        if ($email !== '' && \Illuminate\Support\Facades\Schema::hasColumn('contacts', 'email')) {
            $contact->email = $email;
        }
        if ($notes !== '' && \Illuminate\Support\Facades\Schema::hasColumn('contacts', 'note')) {
            $contact->note = $notes;
        }
        $contact->save();

        // Optional pipeline deal — best-effort, never break the lead capture.
        // Column set mirrors FlowNodeActionsController::deal (the working writer):
        // deals.stage_id / pipeline_id are NOT NULL and there is no user_id or
        // pipeline_stage_id column, so resolve a real pipeline + first stage
        // (ordered by sort_order, the pipeline_stages order column) and bail if
        // the workspace has none rather than throwing on the insert.
        if (($node['createDeal'] ?? true) !== false && class_exists(\App\Models\Deal::class)) {
            try {
                // Same plan gate as every other deal writer (/deals board, REST
                // API, team inbox, flow deal node) — a workspace without the
                // feature cannot open the board, so never leave orphan rows there.
                $dealsOk = \App\Services\PlanLimitGuard::hasFeature(
                    \App\Models\Workspace::find($wsId),
                    'access_sales_pipeline'
                );
                $pipeline = $dealsOk ? \App\Models\Pipeline::ensureDefaultForWorkspace($wsId) : null;
                $stage    = $pipeline
                    ? \App\Models\PipelineStage::where('workspace_id', $wsId)
                        ->where('pipeline_id', $pipeline->id)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->first()
                    : null;

                if (! $dealsOk) {
                    Log::info('[FB-FLOW-NODE] fb_lead deal skipped: access_sales_pipeline not on plan (workspace '.$wsId.')');
                } elseif (! $stage) {
                    Log::info('[FB-FLOW-NODE] fb_lead deal skipped: no pipeline stage for workspace '.$wsId);
                } else {
                    \App\Models\Deal::create([
                        'workspace_id'  => $wsId,
                        'pipeline_id'   => $pipeline->id,
                        'stage_id'      => $stage->id,
                        'contact_id'    => $contact->id,
                        'title'         => mb_substr(($name !== '' ? $name : 'Facebook lead').' — Messenger', 0, 191),
                        'value_minor'   => 0,
                        'currency'      => $pipeline->currency,
                        'owner_user_id' => (int) $page->user_id ?: null,
                        'source'        => 'facebook',
                        'sort_order'    => 0,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[FB-FLOW-NODE] fb_lead deal create failed: '.$e->getMessage());
            }
        }

        // Acknowledge in the DM thread (Node doesn't send anything for this node).
        $ack = $this->subst((string) ($node['ack'] ?? ''), $vars);
        if ($psid !== '' && trim($ack) !== '') {
            $r = (new FacebookPageClient($page))->sendMessage($psid, $ack);
            $this->writeOutbound($page, $psid, $ack, (string) ($r['mid'] ?? ''));
        }

        return response()->json(['ok' => true, 'type' => 'fb_lead', 'vars' => ['lead_id' => $contact->id]]);
    }

    /**
     * fb_products / fb_gallery — send a Messenger generic-template carousel. We
     * build the elements here and POST to /{page}/messages ourselves (self-
     * contained Graph call), then mirror the send into the inbox.
     */
    private function handleCarousel(Request $request, FacebookPage $page, string $type): JsonResponse
    {
        $node = (array) $request->input('node', []);
        $vars = (array) $request->input('vars', []);
        $psid = (string) $request->input('psid', '');
        if ($psid === '') {
            return response()->json(['ok' => true, 'type' => $type]);
        }

        // Optional intro DM before the cards.
        $intro = $this->subst((string) ($node['intro'] ?? ''), $vars);
        if (trim($intro) !== '') {
            $ri = (new FacebookPageClient($page))->sendMessage($psid, $intro);
            $this->writeOutbound($page, $psid, $intro, (string) ($ri['mid'] ?? ''));
        }

        $rows = $type === 'fb_products'
            ? (array) ($node['products'] ?? [])
            : (array) ($node['cards'] ?? []);

        $orderOn    = ($node['orderButton'] ?? true) !== false;
        $orderLabel = trim((string) ($node['orderLabel'] ?? 'Order')) ?: 'Order';
        $detailsOn  = ($node['detailsButton'] ?? true) !== false;
        $detailLabel= trim((string) ($node['detailsLabel'] ?? 'View')) ?: 'View';

        $elements = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $title = mb_substr($this->subst((string) ($row['title'] ?? ''), $vars) ?: 'Item', 0, 80);
            $sub   = mb_substr($this->subst((string) ($row['subtitle'] ?? ''), $vars), 0, 80);
            $img   = trim((string) ($row['imageUrl'] ?? $row['image_url'] ?? ''));
            $url   = trim((string) ($row['url'] ?? $row['buttonUrl'] ?? ''));

            $el = array_filter([
                'title'     => $title,
                'subtitle'  => $sub !== '' ? $sub : null,
                'image_url' => (str_starts_with($img, 'https://')) ? $img : null,
            ], fn ($v) => $v !== null);

            if ($url !== '' && str_starts_with($url, 'http')) {
                $el['default_action'] = ['type' => 'web_url', 'url' => $url];
            }

            $buttons = [];
            if ($type === 'fb_products') {
                if ($orderOn) {
                    $buttons[] = ['type' => 'postback', 'title' => mb_substr($orderLabel, 0, 20),
                        'payload' => 'ORDER_'.mb_substr(preg_replace('/\s+/', '_', $title), 0, 40)];
                }
                if ($detailsOn && $url !== '' && str_starts_with($url, 'http')) {
                    $buttons[] = ['type' => 'web_url', 'url' => $url, 'title' => mb_substr($detailLabel, 0, 20)];
                }
            } else { // fb_gallery
                $label = trim((string) ($row['buttonLabel'] ?? ''));
                if ($label !== '' && $url !== '' && str_starts_with($url, 'http')) {
                    $buttons[] = ['type' => 'web_url', 'url' => $url, 'title' => mb_substr($label, 0, 20)];
                }
            }
            if ($buttons) {
                $el['buttons'] = array_slice($buttons, 0, 3);
            }
            $elements[] = $el;
        }

        if ($elements === []) {
            return response()->json(['ok' => true, 'type' => $type]);
        }

        $v = FacebookPageClient::version();
        Http::withToken((string) $page->access_token)->acceptJson()->timeout(20)
            ->post("https://graph.facebook.com/{$v}/{$page->page_id}/messages", [
                'recipient'      => ['id' => $psid],
                'messaging_type' => 'RESPONSE',
                'message'        => ['attachment' => ['type' => 'template', 'payload' => [
                    'template_type' => 'generic',
                    'elements'      => array_values($elements),
                ]]],
            ]);

        $this->writeOutbound($page, $psid, '['.count($elements).' '.($type === 'fb_products' ? 'product' : 'card').(count($elements) === 1 ? '' : 's').']', '');

        return response()->json(['ok' => true, 'type' => $type]);
    }

    /** Mirror a Page-sent flow message into the unified inbox (same key shape as log()). */
    private function writeOutbound(FacebookPage $page, string $psid, string $body, string $mid = ''): void
    {
        try {
            $wsId = (int) $page->workspace_id;
            $conv = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'facebook', 'raw_jid' => 'fb:'.$page->page_id.':'.$psid],
                [
                    'title'           => $psid,
                    'provider'        => 'facebook',
                    'origin'          => 'facebook',
                    'status'          => 'pending',
                    'inbox_status'    => 'open',
                    'last_message_at' => now(),
                    'contact_digits'  => null,
                ]
            );
            InboxMessage::create(array_filter([
                'conversation_id' => $conv->id,
                'provider'        => 'facebook',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => ['facebook' => array_filter(['message_id' => $mid, 'psid' => $psid, 'kind' => 'dm'], fn ($x) => $x !== null && $x !== '')],
                'sent_at'         => now(),
                'delivered_at'    => now(),
            ], fn ($v) => $v !== null));
            $conv->forceFill(['preview' => Str::limit($body, 120), 'last_message_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[FB-FLOW-NODE] writeOutbound failed: '.$e->getMessage());
        }
    }

    /**
     * Direct cross-channel handoff: read the WhatsApp number the Facebook flow
     * captured (node.numberVar → vars) and START the chosen WhatsApp flow to it
     * via FlowEnrollmentService (which resolves the workspace's WA device and
     * sends). Meta still requires that number to have opted in / be in a 24h
     * window — that's on the operator; here we just launch.
     */
    private function handleHandoff(Request $request): JsonResponse
    {
        try {
            $node = (array) $request->input('node', []);
            $vars = (array) $request->input('vars', []);
            $wsId = (int) $request->input('workspaceId', 0);

            $numberVar = trim((string) ($node['numberVar'] ?? 'wa_number'));
            $flowId    = (int) ($node['waFlowId'] ?? 0);
            $waNum     = preg_replace('/\D+/', '', (string) ($vars[$numberVar] ?? ''));

            if ($wsId <= 0 || $flowId <= 0 || $waNum === '') {
                Log::info('[FB-HANDOFF] skipped — missing ws/flow/number', ['ws' => $wsId, 'flow' => $flowId, 'hasNum' => $waNum !== '']);
                return response()->json(['ok' => true]);
            }

            $flow = Flow::query()->where('workspace_id', $wsId)->whereKey($flowId)->first();
            if (! $flow) {
                Log::info('[FB-HANDOFF] target WhatsApp flow not found', ['ws' => $wsId, 'flow' => $flowId]);
                return response()->json(['ok' => true]);
            }

            // Find an existing contact by the captured number, else create a
            // lightweight one. The saving hook derives mobile_hash; storing the
            // full number in `mobile` with a blank country_code makes
            // FlowEnrollmentService send to exactly these digits.
            $hash    = Contact::hashPhone('', $waNum);
            $contact = Contact::query()->where('workspace_id', $wsId)->where('mobile_hash', $hash)->first();
            if (! $contact) {
                $contact = new Contact();
                $contact->workspace_id = $wsId;
                $contact->user_id      = (int) $flow->user_id;
                $contact->country_code = '';
                $contact->mobile       = $waNum;
                $contact->name         = 'WhatsApp ' . $waNum;
                $contact->save();
            }

            app(FlowEnrollmentService::class)->enroll($contact, $flow, $vars);
            Log::info('[FB-HANDOFF] launched WhatsApp flow', ['ws' => $wsId, 'flow' => $flowId, 'contact' => $contact->id]);
        } catch (\Throwable $e) {
            Log::warning('[FB-HANDOFF] failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
