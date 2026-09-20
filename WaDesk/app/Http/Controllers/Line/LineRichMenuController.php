<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\LineChannel;
use App\Models\LineRichMenu;
use App\Services\Line\LineClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * LINE rich menus — the tappable image panel pinned to the bottom of a chat.
 *
 * The operator picks a preset layout (full / 2-col / 2×2 …), uploads an image
 * sized to that layout, and assigns an action per cell. We register the menu
 * with LINE (returns richMenuId), upload the image, and optionally set it as the
 * OA default so every chat shows it. LINE caps rich-menu CRUD at 100 req/hour —
 * these are one-off operator actions, not a loop, so no pacing is needed.
 */
class LineRichMenuController extends Controller
{
    public function index(): View
    {
        $wsId = $this->workspaceId();

        return view('user.line.rich-menus', [
            'channels' => LineChannel::allForWorkspace($wsId),
            'menus'    => LineRichMenu::where('workspace_id', $wsId)->with('channel')->orderByDesc('id')->get(),
        ]);
    }

    public function create(): View
    {
        $wsId = $this->workspaceId();

        return view('user.line.rich-menus-create', [
            'channels' => LineChannel::allForWorkspace($wsId),
            'layouts'  => LineRichMenu::layouts(),
        ]);
    }

    /**
     * Register a rich menu with LINE + upload its image. The posted per-cell
     * actions are mapped onto the chosen layout's bounds. The image MUST match
     * the layout's exact pixel size (LINE rejects otherwise), so we check the
     * uploaded dimensions before spending an API call.
     */
    public function store(Request $request): RedirectResponse
    {
        $layouts = LineRichMenu::layouts();
        $data = $request->validate([
            'line_channel_id' => ['required', 'integer'],
            'name'            => ['required', 'string', 'max:300'],
            'chat_bar_text'   => ['required', 'string', 'max:14'],
            'layout'          => ['required', 'string', 'in:'.implode(',', array_keys($layouts))],
            'image'           => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024'], // LINE: ≤1MB
            'actions'         => ['required', 'array'],
            'actions.*.type'  => ['nullable', 'string', 'in:message,uri,postback'],
            'actions.*.value' => ['nullable', 'string', 'max:1000'],
            'actions.*.label' => ['nullable', 'string', 'max:20'],
            'set_default'     => ['nullable', 'boolean'],
        ]);

        $wsId    = $this->workspaceId();
        $channel = LineChannel::where('workspace_id', $wsId)->find($data['line_channel_id']);
        if (! $channel) {
            return back()->with('error', 'Pick a LINE channel that belongs to this workspace.')->withInput();
        }

        $layout = $layouts[$data['layout']];
        $cells  = $layout['cells'];
        $file   = $request->file('image');

        // The image must be exactly the layout size or LINE refuses it.
        $dim = @getimagesize($file->getPathname());
        if (! $dim || (int) $dim[0] !== $layout['w'] || (int) $dim[1] !== $layout['h']) {
            return back()->with('error', "The image must be exactly {$layout['w']}×{$layout['h']}px for this layout.")->withInput();
        }

        // Build the LINE areas[] — one per cell, action from the form (default:
        // a no-op message action so a cell is never actionless).
        $areas = [];
        foreach ($cells as $i => $bounds) {
            $a = $data['actions'][$i] ?? [];
            $areas[] = ['bounds' => $bounds, 'action' => $this->buildAction($a)];
        }

        $richMenu = [
            'size'        => ['width' => $layout['w'], 'height' => $layout['h']],
            'selected'    => (bool) ($data['set_default'] ?? false),
            'name'        => mb_substr($data['name'], 0, 300),
            'chatBarText' => mb_substr($data['chat_bar_text'], 0, 14),
            'areas'       => $areas,
        ];

        $client = new LineClient((string) $channel->activeAccessToken());

        $created = $client->createRichMenu($richMenu);
        if (! ($created['ok'] ?? false)) {
            return back()->with('error', 'LINE rejected the menu: '.($created['error'] ?: 'unknown error'))->withInput();
        }
        $richMenuId = (string) ($created['data']['richMenuId'] ?? '');
        if ($richMenuId === '') {
            return back()->with('error', 'LINE did not return a rich menu id.')->withInput();
        }

        // Store the image on our disk (for the manager preview) + upload to LINE.
        $imagePath = $file->store('line-rich-menus', media_disk());
        $bytes = $file->get();
        $ct = strtolower($file->getClientOriginalExtension()) === 'png' ? 'image/png' : 'image/jpeg';
        $up = $client->uploadRichMenuImage($richMenuId, $bytes, $ct);
        if (! ($up['ok'] ?? false)) {
            // Roll back the half-created menu so we never keep an imageless one.
            $client->deleteRichMenu($richMenuId);
            return back()->with('error', 'Image upload failed: '.($up['error'] ?: 'unknown error'))->withInput();
        }

        $menu = LineRichMenu::create([
            'workspace_id'    => $wsId,
            'line_channel_id' => $channel->id,
            'created_by'      => Auth::id(),
            'rich_menu_id'    => $richMenuId,
            'name'            => $data['name'],
            'chat_bar_text'   => $data['chat_bar_text'],
            'layout'          => $data['layout'],
            'size'            => $richMenu['size'],
            'areas'           => $areas,
            'image_path'      => $imagePath,
            'active'          => true,
        ]);

        if (! empty($data['set_default'])) {
            $this->applyDefault($client, $channel->id, $menu);
        }

        return redirect('/line/rich-menus')->with('success', 'Rich menu created'.(! empty($data['set_default']) ? ' and set as the default.' : '.'));
    }

    /** Make this menu the OA default (shows for every user without a per-user menu). */
    public function setDefault(LineRichMenu $richMenu): RedirectResponse
    {
        $this->authorizeMenu($richMenu);
        $channel = $richMenu->channel;
        if (! $channel || ! $richMenu->rich_menu_id) {
            return back()->with('error', 'This menu is not registered with LINE.');
        }
        $client = new LineClient((string) $channel->activeAccessToken());
        $res = $this->applyDefault($client, $channel->id, $richMenu);

        return $res
            ? back()->with('success', 'Set as the default rich menu.')
            : back()->with('error', 'LINE rejected the default request.');
    }

    /** Remove the OA default entirely (no menu shows unless per-user linked). */
    public function clearDefault(LineRichMenu $richMenu): RedirectResponse
    {
        $this->authorizeMenu($richMenu);
        $channel = $richMenu->channel;
        if ($channel) {
            (new LineClient((string) $channel->activeAccessToken()))->cancelDefaultRichMenu();
        }
        LineRichMenu::where('line_channel_id', $richMenu->line_channel_id)->update(['is_default' => false]);

        return back()->with('success', 'Default rich menu cleared.');
    }

    public function destroy(LineRichMenu $richMenu): RedirectResponse
    {
        $this->authorizeMenu($richMenu);
        $channel = $richMenu->channel;
        if ($channel && $richMenu->rich_menu_id) {
            (new LineClient((string) $channel->activeAccessToken()))->deleteRichMenu((string) $richMenu->rich_menu_id);
        }
        if ($richMenu->image_path) {
            try { Storage::disk(media_disk())->delete($richMenu->image_path); } catch (\Throwable $e) {}
        }
        $richMenu->delete();

        return back()->with('success', 'Rich menu deleted.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** One posted cell action → a LINE action object (default: harmless message). */
    private function buildAction(array $a): array
    {
        $type  = strtolower((string) ($a['type'] ?? 'message'));
        $value = trim((string) ($a['value'] ?? ''));
        $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, 20);

        if ($type === 'uri' && $value !== '') {
            return array_filter(['type' => 'uri', 'label' => $label ?: null, 'uri' => $value]);
        }
        if ($type === 'postback' && $value !== '') {
            return array_filter(['type' => 'postback', 'label' => $label ?: null, 'data' => mb_substr($value, 0, 300), 'displayText' => $label ?: null]);
        }

        // message (or an empty cell) — a tap sends the label/value text.
        $text = $value !== '' ? $value : ($label !== '' ? $label : ' ');

        return ['type' => 'message', 'label' => $label ?: mb_substr($text, 0, 20), 'text' => $text];
    }

    /** Push the default to LINE and flip the local flag exclusively to this menu. */
    private function applyDefault(LineClient $client, int $channelId, LineRichMenu $menu): bool
    {
        $res = $client->setDefaultRichMenu((string) $menu->rich_menu_id);
        if (! ($res['ok'] ?? false)) {
            return false;
        }
        LineRichMenu::where('line_channel_id', $channelId)->update(['is_default' => false]);
        $menu->forceFill(['is_default' => true])->save();

        return true;
    }

    private function authorizeMenu(LineRichMenu $menu): void
    {
        abort_unless((int) $menu->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
