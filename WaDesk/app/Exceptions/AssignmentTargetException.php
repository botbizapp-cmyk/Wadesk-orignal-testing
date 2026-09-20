<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Services\Inbox\AssignmentService when the requested assignee
 * cannot legally receive the conversation:
 *
 *   - 'user_not_in_workspace' : the member id belongs to another workspace, or
 *     to an account that has been removed / deactivated since. Writing null in
 *     that case silently UNASSIGNED the chat while still answering ok, so the
 *     service refuses instead — explicit unassign has its own path.
 *   - 'team_not_in_workspace' : same, for the team id.
 *
 * Renderable so any endpoint that forgets to catch it still answers 422 with a
 * translated message rather than a 500.
 */
class AssignmentTargetException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($this->humanMessage());
    }

    public function humanMessage(): string
    {
        return $this->reason === 'team_not_in_workspace'
            ? __('That team is not part of this workspace.')
            : __('That member is no longer active in this workspace.');
    }

    public function render($request)
    {
        $payload = [
            'ok'      => false,
            'error'   => 'assignment_target_invalid',
            'reason'  => $this->reason,
            'message' => $this->humanMessage(),
        ];
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($payload, 422);
        }
        return back()->with('error', $this->humanMessage());
    }
}
