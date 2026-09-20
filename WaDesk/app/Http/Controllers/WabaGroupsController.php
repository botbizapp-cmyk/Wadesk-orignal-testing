<?php

namespace App\Http\Controllers;

use App\Models\WabaGroup;
use App\Models\WaProviderConfig;
use App\Services\Waba\GroupClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * WhatsApp **Cloud API** groups (/waba-groups).
 *
 * Separate surface from /store/groups, which lists Unofficial-API groups. The
 * two never mix: a Cloud API group has an opaque `group_id` and no jid, and can
 * only be reached through the account that owns it.
 *
 * Meta gates this behind an Official Business Account, so every screen has to
 * cope with "your number cannot use this at all" as a first-class state rather
 * than as an error.
 */
class WabaGroupsController extends Controller
{
    /** GET /waba-groups */
    public function index(Request $request): View
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);

        $accounts = WaProviderConfig::query()
            ->where('workspace_id', $wsId)
            ->where('provider', 'waba')
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->get();

        $account = $accounts->firstWhere('id', (int) $request->query('account'))
            ?: $accounts->first();

        // Eligibility is an account property, and the client caches it — so
        // this is one cheap call, not one per page load.
        $eligible = $account ? (new GroupClient($account))->isEligible() : false;

        $groups = $account
            ? WabaGroup::query()->where('provider_config_id', $account->id)->orderByDesc('id')->get()
            : collect();

        return view('user.waba-groups.index', [
            'accounts' => $accounts,
            'account'  => $account,
            'eligible' => $eligible,
            'groups'   => $groups,
            'stats'    => [
                'total'        => $groups->count(),
                'participants' => (int) $groups->sum('participant_count'),
                'suspended'    => $groups->where('suspended', true)->count(),
                'pending'      => $groups->sum(fn ($g) => count((array) ($g->meta_json['join_requests'] ?? []))),
            ],
        ]);
    }

    /** POST /waba-groups — create a group on Meta. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'account_id'         => 'required|integer',
            'subject'            => 'required|string|max:128',
            'description'        => 'nullable|string|max:2048',
            'join_approval_mode' => 'nullable|in:approval_required,auto_approve',
        ]);

        $account = $this->account((int) $data['account_id']);
        if (! $account) {
            return back()->withErrors(['group' => __('Pick a connected WhatsApp Business number first.')]);
        }

        $res = (new GroupClient($account))->create(
            $data['subject'],
            (string) ($data['description'] ?? ''),
            (string) ($data['join_approval_mode'] ?? '')
        );
        if (! ($res['ok'] ?? false)) {
            return back()->withErrors(['group' => $res['error']]);
        }

        // Record what we know now. Meta sends the INVITE LINK separately on the
        // group_lifecycle_update webhook, so the row is deliberately created
        // without one — the list shows "link pending" until that arrives.
        $groupId = (string) ($res['data']['id'] ?? $res['data']['group_id'] ?? '');
        if ($groupId !== '') {
            WabaGroup::updateOrCreate(
                ['provider_config_id' => $account->id, 'group_id' => $groupId],
                [
                    'workspace_id'       => $account->workspace_id,
                    'subject'            => $data['subject'],
                    'description'        => $data['description'] ?? null,
                    'join_approval_mode' => $data['join_approval_mode'] ?? null,
                    'synced_at'          => now(),
                ]
            );
        }

        return back()->with('status', __('Group created. The invite link appears here as soon as Meta issues it.'));
    }

    /** POST /waba-groups/{group}/sync — pull this group's current state. */
    public function sync(Request $request, int $group): RedirectResponse
    {
        $row = $this->group($group);
        if (! $row) {
            return back()->withErrors(['group' => __('Group not found.')]);
        }

        $client = new GroupClient($row->provider);
        $info   = $client->show($row->group_id);
        if (! ($info['ok'] ?? false)) {
            return back()->withErrors(['group' => $info['error']]);
        }

        $d = $info['data'] ?? [];
        $row->fill([
            'subject'            => $d['subject'] ?? $row->subject,
            'description'        => $d['description'] ?? $row->description,
            'join_approval_mode' => $d['join_approval_mode'] ?? $row->join_approval_mode,
            'participant_count'  => (int) ($d['total_participant_count'] ?? $row->participant_count),
            'suspended'          => (bool) ($d['suspended'] ?? $row->suspended),
            'synced_at'          => now(),
        ]);

        // Refresh the link too — it may have been reset from a phone.
        $link = $client->inviteLink($row->group_id);
        if ($link['ok'] ?? false) {
            $row->invite_link = (string) ($link['data']['invite_link'] ?? $row->invite_link);
        }
        $row->save();

        return back()->with('status', __('Group refreshed.'));
    }

    /** POST /waba-groups/{group}/reset-link */
    public function resetLink(Request $request, int $group): RedirectResponse
    {
        $row = $this->group($group);
        if (! $row) {
            return back()->withErrors(['group' => __('Group not found.')]);
        }

        $res = (new GroupClient($row->provider))->resetInviteLink($row->group_id);
        if (! ($res['ok'] ?? false)) {
            return back()->withErrors(['group' => $res['error']]);
        }

        $row->forceFill(['invite_link' => (string) ($res['data']['invite_link'] ?? '')])->save();

        return back()->with('status', __('New invite link created. Every link shared before now has stopped working.'));
    }

    /** POST /waba-groups/{group}/settings */
    public function update(Request $request, int $group): RedirectResponse
    {
        $data = $request->validate([
            'subject'     => 'nullable|string|max:128',
            'description' => 'nullable|string|max:2048',
        ]);

        $row = $this->group($group);
        if (! $row) {
            return back()->withErrors(['group' => __('Group not found.')]);
        }

        $res = (new GroupClient($row->provider))->update($row->group_id, array_filter($data, fn ($v) => $v !== null));
        if (! ($res['ok'] ?? false)) {
            return back()->withErrors(['group' => $res['error']]);
        }

        // Deliberately NOT written locally here. Meta confirms each field
        // separately on group_settings_update, and a subject can succeed while
        // a description fails — the webhook is the only source of truth for
        // what actually landed.
        return back()->with('status', __('Update sent to WhatsApp. The group refreshes here once Meta confirms it.'));
    }

    /** DELETE /waba-groups/{group} */
    public function destroy(Request $request, int $group): RedirectResponse
    {
        $row = $this->group($group);
        if (! $row) {
            return back()->withErrors(['group' => __('Group not found.')]);
        }

        $res = (new GroupClient($row->provider))->delete($row->group_id);
        if (! ($res['ok'] ?? false)) {
            return back()->withErrors(['group' => $res['error']]);
        }
        $row->delete();

        return back()->with('status', __('Group deleted and every participant removed.'));
    }

    /** POST /waba-groups/{group}/join-requests — approve or reject. */
    public function joinRequests(Request $request, int $group): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'string',
        ]);

        $row = $this->group($group);
        if (! $row) {
            return response()->json(['ok' => false, 'message' => __('Group not found.')], 404);
        }

        $client = new GroupClient($row->provider);
        $res = $data['action'] === 'approve'
            ? $client->approveJoinRequests($row->group_id, $data['ids'])
            : $client->rejectJoinRequests($row->group_id, $data['ids']);

        if (! ($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => $res['error']], 422);
        }

        // Clear the handled ids from the local queue; the participant webhook
        // corrects the member count.
        $meta    = is_array($row->meta_json) ? $row->meta_json : [];
        $pending = (array) ($meta['join_requests'] ?? []);
        foreach ($data['ids'] as $id) {
            unset($pending[$id]);
        }
        $meta['join_requests'] = $pending;
        $row->forceFill(['meta_json' => $meta])->save();

        return response()->json(['ok' => true, 'handled' => count($data['ids'])]);
    }

    /** DELETE /waba-groups/{group}/participants — up to 8 per call (Meta's cap). */
    public function removeParticipants(Request $request, int $group): JsonResponse
    {
        $data = $request->validate([
            'participants'   => 'required|array|min:1|max:8',
            'participants.*' => 'string',
        ]);

        $row = $this->group($group);
        if (! $row) {
            return response()->json(['ok' => false, 'message' => __('Group not found.')], 404);
        }

        $res = (new GroupClient($row->provider))->removeParticipants($row->group_id, $data['participants']);
        if (! ($res['ok'] ?? false)) {
            return response()->json(['ok' => false, 'message' => $res['error']], 422);
        }

        return response()->json(['ok' => true, 'removed' => count($data['participants'])]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** A connected WABA account in the CURRENT workspace, or null. */
    private function account(int $id): ?WaProviderConfig
    {
        return WaProviderConfig::query()
            ->where('workspace_id', (int) (Auth::user()?->current_workspace_id ?? 0))
            ->where('provider', 'waba')
            ->find($id);
    }

    /** A group row in the CURRENT workspace, or null — never cross-tenant. */
    private function group(int $id): ?WabaGroup
    {
        return WabaGroup::query()
            ->where('workspace_id', (int) (Auth::user()?->current_workspace_id ?? 0))
            ->with('provider')
            ->find($id);
    }
}
