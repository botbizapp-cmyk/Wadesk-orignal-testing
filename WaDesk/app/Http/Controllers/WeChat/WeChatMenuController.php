<?php

namespace App\Http\Controllers\WeChat;

use App\Http\Controllers\Controller;
use App\Models\WeChatChannel;
use App\Services\WeChat\WeChatClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * WeChat custom menu (自定义菜单) — the persistent bottom-bar menu of an Official
 * Account (the WeChat analog of a LINE rich menu). An OA has exactly ONE menu, so
 * this is a builder that pushes the whole structure: up to 3 top buttons, each a
 * leaf (view URL / click keyword / mini-program) or a parent with up to 5
 * sub-buttons. A `click` button fires an EventKey the keyword/flow engine matches.
 */
class WeChatMenuController extends Controller
{
    public function index(Request $request): View
    {
        $wsId = $this->workspaceId();
        $channels = WeChatChannel::allForWorkspace($wsId);
        $channel  = $request->integer('channel') > 0
            ? $channels->firstWhere('id', $request->integer('channel'))
            : $channels->first();

        $current = null;
        if ($channel) {
            $res = (new WeChatClient($channel))->getMenu();
            if ($res['ok'] ?? false) {
                $current = data_get($res, 'data.menu.button') ?: data_get($res, 'data.selfmenu_info.button');
            }
        }

        return view('user.wechat.menu', [
            'channels' => $channels,
            'channel'  => $channel,
            'current'  => $current,
        ]);
    }

    /** Build + push the menu to WeChat. */
    public function save(Request $request): RedirectResponse
    {
        $wsId = $this->workspaceId();
        $data = $request->validate([
            'wechat_channel_id' => ['required', 'integer'],
            'menu'              => ['required', 'string', 'max:8000'],   // JSON from the builder
        ]);
        $channel = WeChatChannel::where('workspace_id', $wsId)->find($data['wechat_channel_id']);
        if (! $channel) {
            return back()->with('error', 'Pick a WeChat channel that belongs to this workspace.');
        }

        $posted = json_decode($data['menu'], true);
        if (! is_array($posted) || $posted === []) {
            return back()->with('error', 'The menu is empty — add at least one button.');
        }

        $buttons = [];
        foreach (array_slice($posted, 0, 3) as $b) {
            $name = trim((string) ($b['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $subs = is_array($b['sub'] ?? null) ? array_slice($b['sub'], 0, 5) : [];
            $subButtons = [];
            foreach ($subs as $s) {
                if (trim((string) ($s['name'] ?? '')) !== '') {
                    $subButtons[] = $this->buildButton($s);
                }
            }
            $buttons[] = $subButtons
                ? ['name' => mb_substr($name, 0, 16), 'sub_button' => $subButtons]
                : $this->buildButton(array_merge($b, ['name' => $name]));
        }

        if (! $buttons) {
            return back()->with('error', 'Add at least one named button.');
        }

        $res = (new WeChatClient($channel))->createMenu($buttons);
        if (! ($res['ok'] ?? false)) {
            return back()->with('error', 'WeChat rejected the menu: '.($res['error'] ?: 'unknown error'));
        }

        return redirect('/wechat/menu?channel='.$channel->id)->with('success', 'Menu published to WeChat.');
    }

    /** Remove the menu entirely. */
    public function clear(Request $request): RedirectResponse
    {
        $wsId = $this->workspaceId();
        $channel = WeChatChannel::where('workspace_id', $wsId)->find($request->integer('wechat_channel_id'));
        if ($channel) {
            (new WeChatClient($channel))->deleteMenu();
        }

        return back()->with('success', 'Menu cleared.');
    }

    /** One posted leaf → a WeChat menu button object. */
    private function buildButton(array $b): array
    {
        $name = mb_substr(trim((string) ($b['name'] ?? '')), 0, 16);
        $type = strtolower((string) ($b['type'] ?? 'click'));
        $val  = trim((string) ($b['value'] ?? ''));

        if ($type === 'view') {
            return ['type' => 'view', 'name' => $name, 'url' => $val ?: 'https://weixin.qq.com'];
        }
        if ($type === 'miniprogram') {
            return array_filter([
                'type' => 'miniprogram', 'name' => $name,
                'url'  => trim((string) ($b['url'] ?? '')) ?: 'https://weixin.qq.com',
                'appid' => trim((string) ($b['appid'] ?? '')),
                'pagepath' => trim((string) ($b['pagepath'] ?? '')),
            ], fn ($v) => $v !== '');
        }

        // click — fires this key as an EventKey (keyword/flow can match it).
        return ['type' => 'click', 'name' => $name, 'key' => ($val ?: $name)];
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
