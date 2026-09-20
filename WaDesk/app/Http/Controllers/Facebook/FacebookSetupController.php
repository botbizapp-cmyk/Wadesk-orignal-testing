<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Services\Facebook\FacebookPageClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Messenger Profile setup — the ManyChat-style "growth tools" basics for a
 * connected Facebook Page: Get Started button, Persistent Menu, Ice Breakers
 * and the welcome Greeting. Reads the live profile via
 * FacebookPageClient::getMessengerProfile and writes each section through the
 * matching set / delete helper. One focused settings screen per Page.
 *
 * Mirrors FacebookInsightsController / FacebookPostController for workspace
 * scoping, Page resolution and the connected() gate.
 */
class FacebookSetupController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Connected Pages for the current workspace, alphabetical. */
    private function pages()
    {
        return FacebookPage::forWorkspace($this->wsId())->connected()->orderBy('name')->get();
    }

    /** Resolve the selected Page from ?page= (mirrors the insights picker). */
    private function selectedPage(Request $request, $pages): ?FacebookPage
    {
        if ($pages->isEmpty()) {
            return null;
        }
        $pageId = (int) $request->integer('page', $pages->first()->id);

        return $pages->firstWhere('id', $pageId) ?: $pages->first();
    }

    /** Resolve a connected Page from a posted facebook_page_id, or null. */
    private function pageFromRequest(Request $request): ?FacebookPage
    {
        return FacebookPage::forWorkspace($this->wsId())->connected()
            ->whereKey((int) $request->integer('facebook_page_id'))->first();
    }

    public function index(Request $request): View
    {
        $pages = $this->pages();
        $page = $this->selectedPage($request, $pages);

        // Flatten the messenger_profile data[] (each element is one {property:value}).
        $profile = [];
        if ($page) {
            foreach ((new FacebookPageClient($page))->getMessengerProfile() as $row) {
                foreach ((array) $row as $k => $v) {
                    $profile[$k] = $v;
                }
            }
        }

        // Get Started payload.
        $getStarted = (string) data_get($profile, 'get_started.payload', '');

        // Greeting — take the default-locale text (Meta always stores 'default').
        $greeting = '';
        foreach ((array) ($profile['greeting'] ?? []) as $g) {
            if (($g['locale'] ?? '') === 'default') {
                $greeting = (string) ($g['text'] ?? '');
                break;
            }
            $greeting = (string) ($g['text'] ?? $greeting);
        }

        // Persistent menu — default locale block.
        $menuItems = [];
        $composerDisabled = false;
        $menuBlocks = (array) ($profile['persistent_menu'] ?? []);
        $menuBlock = collect($menuBlocks)->firstWhere('locale', 'default') ?: ($menuBlocks[0] ?? null);
        if (is_array($menuBlock)) {
            $composerDisabled = (bool) ($menuBlock['composer_input_disabled'] ?? false);
            foreach ((array) ($menuBlock['call_to_actions'] ?? []) as $cta) {
                $isUrl = ($cta['type'] ?? '') === 'web_url';
                $menuItems[] = [
                    'title'   => (string) ($cta['title'] ?? ''),
                    'type'    => $isUrl ? 'web_url' : 'postback',
                    'url'     => (string) ($cta['url'] ?? ''),
                    'payload' => (string) ($cta['payload'] ?? ''),
                ];
            }
        }

        // Ice breakers — accept both the new localized shape (call_to_actions)
        // and the legacy flat [{question,payload}] shape.
        $iceBreakers = [];
        $ibRaw = (array) ($profile['ice_breakers'] ?? []);
        $ibBlock = collect($ibRaw)->firstWhere('locale', 'default');
        $ctas = is_array($ibBlock) && isset($ibBlock['call_to_actions'])
            ? (array) $ibBlock['call_to_actions']
            : (isset($ibRaw[0]['call_to_actions']) ? (array) $ibRaw[0]['call_to_actions'] : $ibRaw);
        foreach ($ctas as $ib) {
            if (! is_array($ib)) {
                continue;
            }
            $iceBreakers[] = [
                'question' => (string) ($ib['question'] ?? ''),
                'payload'  => (string) ($ib['payload'] ?? ''),
            ];
        }

        return view('user.facebook.setup', compact(
            'pages', 'page', 'getStarted', 'greeting',
            'menuItems', 'composerDisabled', 'iceBreakers'
        ));
    }

    /** Redirect back to the setup screen for the given Page id. */
    private function backTo(int $pageId): RedirectResponse
    {
        return redirect()->route('user.facebook.setup', ['page' => $pageId]);
    }

    /** Standardise a client {ok,error} result into a redirect. */
    private function finish(array $res, int $pageId, string $ok): RedirectResponse
    {
        if (! empty($res['ok'])) {
            return $this->backTo($pageId)->with('status', $ok);
        }

        // `unsupported` means Facebook has retired the field, not that it
        // refused this particular change — the message already explains what to
        // do instead, so don't bury it under "Facebook rejected the change".
        if (! empty($res['unsupported'])) {
            return $this->backTo($pageId)->withErrors(['facebook' => $res['error']]);
        }

        return $this->backTo($pageId)->withErrors(['facebook' => __('Facebook rejected the change: ').($res['error'] ?? __('unknown error'))]);
    }

    // ── Get Started ──────────────────────────────────────────────────────────
    public function updateGetStarted(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }
        $payload = trim((string) $request->input('payload', ''));
        $client = new FacebookPageClient($page);

        $res = $payload === '' ? $client->deleteGetStarted() : $client->setGetStarted($payload);

        return $this->finish($res, $page->id, $payload === '' ? __('Get Started button removed.') : __('Get Started button saved.'));
    }

    public function deleteGetStarted(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }

        return $this->finish((new FacebookPageClient($page))->deleteGetStarted(), $page->id, __('Get Started button removed.'));
    }

    // ── Greeting ─────────────────────────────────────────────────────────────
    public function updateGreeting(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }
        $text = trim((string) $request->input('greeting', ''));
        $client = new FacebookPageClient($page);

        if ($text === '') {
            return $this->finish($client->deleteGreeting(), $page->id, __('Greeting removed.'));
        }
        // Meta caps the greeting at 160 characters.
        $text = mb_substr($text, 0, 160);
        $res = $client->setGreeting([['locale' => 'default', 'text' => $text]]);

        return $this->finish($res, $page->id, __('Greeting saved.'));
    }

    public function deleteGreeting(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }

        return $this->finish((new FacebookPageClient($page))->deleteGreeting(), $page->id, __('Greeting removed.'));
    }

    // ── Persistent Menu ──────────────────────────────────────────────────────
    public function updateMenu(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }
        $client = new FacebookPageClient($page);

        $titles   = (array) $request->input('menu_title', []);
        $types    = (array) $request->input('menu_type', []);
        $urls     = (array) $request->input('menu_url', []);
        $payloads = (array) $request->input('menu_payload', []);

        $ctas = [];
        foreach ($titles as $i => $title) {
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $type = ($types[$i] ?? 'postback') === 'web_url' ? 'web_url' : 'postback';
            if ($type === 'web_url') {
                $url = trim((string) ($urls[$i] ?? ''));
                if ($url === '') {
                    continue;
                }
                $ctas[] = ['type' => 'web_url', 'title' => mb_substr($title, 0, 30), 'url' => $url, 'webview_height_ratio' => 'full'];
            } else {
                $payload = trim((string) ($payloads[$i] ?? ''));
                $ctas[] = ['type' => 'postback', 'title' => mb_substr($title, 0, 30), 'payload' => $payload !== '' ? $payload : 'MENU_'.($i + 1)];
            }
            if (count($ctas) >= 3) { // Meta allows up to 3 top-level items.
                break;
            }
        }

        if (empty($ctas)) {
            return $this->finish($client->deletePersistentMenu(), $page->id, __('Persistent menu removed.'));
        }

        // A persistent menu requires the Get Started button to exist first.
        $hasGetStarted = false;
        foreach ($client->getMessengerProfile(['get_started']) as $row) {
            if (isset($row['get_started'])) {
                $hasGetStarted = true;
                break;
            }
        }
        if (! $hasGetStarted) {
            $client->setGetStarted('GET_STARTED');
        }

        $res = $client->setPersistentMenu([[
            'locale'                 => 'default',
            'composer_input_disabled' => $request->boolean('composer_input_disabled'),
            'call_to_actions'        => $ctas,
        ]]);

        return $this->finish($res, $page->id, __('Persistent menu saved.'));
    }

    public function deleteMenu(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }

        return $this->finish((new FacebookPageClient($page))->deletePersistentMenu(), $page->id, __('Persistent menu removed.'));
    }

    // ── Ice Breakers ─────────────────────────────────────────────────────────
    public function updateIceBreakers(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }
        $client = new FacebookPageClient($page);

        $questions = (array) $request->input('ib_question', []);
        $payloads  = (array) $request->input('ib_payload', []);

        $ctas = [];
        foreach ($questions as $i => $question) {
            $question = trim((string) $question);
            if ($question === '') {
                continue;
            }
            $payload = trim((string) ($payloads[$i] ?? ''));
            $ctas[] = [
                'question' => mb_substr($question, 0, 80),
                'payload'  => $payload !== '' ? $payload : 'ICE_BREAKER_'.($i + 1),
            ];
            if (count($ctas) >= 4) { // Meta allows up to 4 ice breakers per locale.
                break;
            }
        }

        if (empty($ctas)) {
            return $this->finish($client->deleteIceBreakers(), $page->id, __('Ice breakers removed.'));
        }

        $res = $client->setIceBreakers([[
            'call_to_actions' => $ctas,
            'locale'          => 'default',
        ]]);

        return $this->finish($res, $page->id, __('Ice breakers saved.'));
    }

    public function deleteIceBreakers(Request $request): RedirectResponse
    {
        $page = $this->pageFromRequest($request);
        if (! $page) {
            return back()->withErrors(['facebook' => __('Pick a connected Facebook Page.')]);
        }

        return $this->finish((new FacebookPageClient($page))->deleteIceBreakers(), $page->id, __('Ice breakers removed.'));
    }
}
