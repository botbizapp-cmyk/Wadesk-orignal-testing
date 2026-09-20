<?php

namespace App\Http\Controllers;

use App\Models\AiCrmAction;
use App\Models\Workspace;
use App\Services\AiCrm\AiCrmCopilotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AI CRM Copilot — dashboard chat surface.
 *
 * Renders the chat page and relays each turn to AiCrmCopilotService (the same
 * service the WhatsApp staff channel uses). Conversation history is kept in the
 * session per workspace; confirm-before-act state lives in the cache inside the
 * service, so "reply YES" works identically here and over WhatsApp.
 */
class AiCrmController extends Controller
{
    private const CHANNEL = 'dashboard';
    private const HISTORY_MAX = 12;

    public function __construct(private AiCrmCopilotService $copilot)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $wsId = (int) ($user->current_workspace_id ?? 0);
        $ws   = Workspace::find($wsId);

        $recent = AiCrmAction::where('workspace_id', $wsId)
            ->whereIn('status', ['ok', 'confirmed'])
            ->latest('id')->limit(12)
            ->get(['tool', 'result_summary', 'created_at']);

        return view('user.ai-crm.index', [
            'history'      => $this->history($wsId),
            'recent'       => $recent,
            'waEnabled'    => (bool) ($ws->wa_copilot_enabled ?? false),
            'canToggleWa'  => in_array($user->workspaceRole(), ['admin', 'owner'], true)
                              || in_array($user->role ?? null, ['admin', 'super-admin', 'super_admin', 'platform-admin'], true),
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);
        $user = $request->user();
        $ws   = Workspace::find((int) ($user->current_workspace_id ?? 0));
        if (!$ws) {
            return response()->json(['ok' => false, 'reply' => 'No active workspace.'], 422);
        }

        $message = trim($data['message']);
        $history = $this->history($ws->id);

        $result = $this->copilot->ask($ws, $user, self::CHANNEL, $history, $message);

        // Persist the turn (user + assistant) for follow-up context.
        $history[] = ['role' => 'user', 'text' => $message];
        $history[] = ['role' => 'assistant', 'text' => (string) ($result['reply'] ?? '')];
        $this->setHistory($ws->id, $history);

        return response()->json([
            'ok'       => true,
            'reply'    => $result['reply'] ?? '',
            'actions'  => $result['actions'] ?? [],
            'pending'  => $result['pending'] ?? null,
            'provider' => $result['provider'] ?? null,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        session()->forget($this->key($wsId));
        return response()->json(['ok' => true]);
    }

    /** Toggle the WhatsApp staff-command channel (admins only — route-gated). */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['wa_copilot_enabled' => 'required|boolean']);
        $ws = Workspace::find((int) ($request->user()->current_workspace_id ?? 0));
        if (!$ws) {
            return response()->json(['ok' => false], 422);
        }
        $ws->update(['wa_copilot_enabled' => (bool) $data['wa_copilot_enabled']]);
        return response()->json(['ok' => true, 'wa_copilot_enabled' => (bool) $ws->wa_copilot_enabled]);
    }

    // ---- session history helpers -------------------------------------------

    private function key(int $wsId): string
    {
        return "aicrm_history_{$wsId}";
    }

    private function history(int $wsId): array
    {
        $h = session($this->key($wsId), []);
        return is_array($h) ? $h : [];
    }

    private function setHistory(int $wsId, array $history): void
    {
        // Keep only the last N turns so the prompt stays bounded.
        if (count($history) > self::HISTORY_MAX) {
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        session([$this->key($wsId) => $history]);
    }
}
