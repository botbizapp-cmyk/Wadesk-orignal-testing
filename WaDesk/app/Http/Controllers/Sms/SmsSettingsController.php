<?php

namespace App\Http\Controllers\Sms;

use App\Http\Controllers\Controller;
use App\Models\WaProviderConfig;
use App\Services\Sms\SmsLookup;
use App\Services\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * SMS channel settings — lists the workspace's connected SMS numbers and hosts
 * the connect form. SMS numbers are ordinary WaProviderConfig provider='sms'
 * rows (the same device/provider store WhatsApp uses); this page is just the
 * SMS-scoped view of them.
 */
class SmsSettingsController extends Controller
{
    public function index(): View
    {
        $wsId = (int) (Auth::user()->current_workspace_id ?? 0);

        $senders = $wsId
            ? WaProviderConfig::query()->forWorkspace($wsId)->where('provider', 'sms')->orderBy('id')->get()
            : collect();

        // Does the workspace already have Twilio (WhatsApp) connected? Drives the
        // "reuse your existing Twilio keys" hint on the connect form.
        $hasTwilio = $wsId
            ? WaProviderConfig::query()->forWorkspace($wsId)->where('provider', 'twilio')->exists()
            : false;

        return view('user.sms.index', compact('senders', 'hasTwilio'));
    }

    /** POST /sms/{id}/toggle — activate / deactivate an SMS sender. */
    public function toggle(int $id): RedirectResponse
    {
        $wsId = (int) (Auth::user()->current_workspace_id ?? 0);
        $cfg = WaProviderConfig::query()->where('id', $id)->where('workspace_id', $wsId)
            ->where('provider', 'sms')->first();

        if (! $cfg) {
            return back()->with('error', 'SMS number not found.');
        }

        $cfg->status = $cfg->status === WaProviderConfig::STATUS_CONNECTED
            ? WaProviderConfig::STATUS_DISCONNECTED
            : WaProviderConfig::STATUS_CONNECTED;
        $cfg->save();
        \App\Services\WorkspaceEngine::flush();

        return back()->with('status', 'SMS number ' . ($cfg->status === WaProviderConfig::STATUS_CONNECTED ? 'activated' : 'deactivated') . '.');
    }

    /** DELETE /sms/{id} — remove an SMS sender. */
    public function destroy(int $id): RedirectResponse
    {
        $wsId = (int) (Auth::user()->current_workspace_id ?? 0);
        $cfg = WaProviderConfig::query()->where('id', $id)->where('workspace_id', $wsId)
            ->where('provider', 'sms')->first();

        if ($cfg) {
            $cfg->delete();
            \App\Services\WorkspaceEngine::flush();
        }

        return back()->with('status', 'SMS number removed.');
    }

    /**
     * POST /sms/lookup — is a number worth texting? (Twilio Lookup — mobile /
     * landline / invalid + carrier.) Saves money on lists full of landlines and
     * typos, since SMS bills on submission, not delivery.
     */
    public function lookup(Request $request): JsonResponse
    {
        $phone = trim((string) $request->input('phone', ''));
        $wsId  = (int) (Auth::user()->current_workspace_id ?? 0);

        $sender = SmsSender::firstForWorkspace($wsId);
        if (! $sender) {
            return response()->json(['ok' => false, 'reason' => 'Connect a Twilio SMS number first.', 'describe' => 'Connect a Twilio SMS number first.']);
        }

        $r = (new SmsLookup($sender))->check($phone);
        $r['describe'] = SmsLookup::describe($r);

        return response()->json($r);
    }
}
