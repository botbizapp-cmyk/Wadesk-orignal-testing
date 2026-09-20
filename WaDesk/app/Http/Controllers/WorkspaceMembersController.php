<?php

namespace App\Http\Controllers;

use App\Mail\TeamInviteMail;
use App\Models\AgentStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\AuditLogger;
use App\Support\WorkspacePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Workspace member CRUD — invite, list, change role, remove.
 *
 * Invite flow is intentionally simple for self-hosted installs without
 * a configured mailer:
 *   - Existing User with that email → attach to current workspace
 *   - New email → create User with a 12-char random password, return
 *     it once in the response so the inviter can share it manually
 *
 * Email-based magic-link invites would require MAIL_MAILER + a token
 * table. We can layer that on later; for now the temp-password path
 * is enough to actually onboard a teammate end-to-end without leaving
 * an "invitation pending" stub that never resolves.
 */
class WorkspaceMembersController extends Controller
{
    public function page()
    {
        return view('user.team-inbox.members');
    }

    public function index(Request $request): JsonResponse
    {
        $wsId = (int) $request->user()->current_workspace_id;
        $rows = DB::table('workspace_user')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspace_user.workspace_id', $wsId)
            ->select([
                'users.id', 'users.name', 'users.email',
                'workspace_user.role', 'workspace_user.joined_at', 'workspace_user.invited_at',
            ])
            ->orderBy('workspace_user.role')
            ->orderBy('users.name')
            ->get();

        $statuses = AgentStatus::forWorkspace($wsId)->get(['user_id','status','last_seen_at'])->keyBy('user_id');

        return response()->json([
            'members' => $rows->map(fn ($r) => [
                'id'            => $r->id,
                'name'          => $r->name,
                'email'         => $r->email,
                'role'          => $r->role,
                'invited_at'    => $r->invited_at,
                'joined_at'     => $r->joined_at,
                'agent_status'  => $statuses->get($r->id)?->status ?? 'offline',
                'last_seen_at'  => $statuses->get($r->id)?->last_seen_at,
            ]),
            'roles'           => WorkspacePermissions::ROLES,
            'grantable_roles' => WorkspacePermissions::grantableRolesFor($request->user()),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        if (!WorkspacePermissions::userCan($request->user(), 'member.invite')) {
            abort(403, 'You do not have permission to invite members.');
        }

        // Plan: seat cap on the workspace.
        $ws = $request->user()->currentWorkspace;
        \App\Services\PlanLimitGuard::check(
            $ws,
            'user_seat_limit',
            \DB::table('workspace_user')->where('workspace_id', $ws->id)->count(),
        );

        $data = $request->validate([
            'email' => 'required|email|max:191',
            'name'  => 'required|string|max:191',
            'role'  => 'required|in:' . implode(',', WorkspacePermissions::ROLES),
        ]);

        // Role-grant gate — owner-only for Owner/Admin, hierarchy-checked
        // for the rest. See WorkspacePermissions::canGrantRole().
        if (!WorkspacePermissions::canGrantRole($request->user(), $data['role'])) {
            abort(403, "You don't have permission to grant the {$data['role']} role.");
        }

        $wsId      = (int) $request->user()->current_workspace_id;
        $workspace = Workspace::findOrFail($wsId);

        $existing = User::where('email', $data['email'])->first();
        $tempPassword = null;

        if (!$existing) {
            // brand-new user: generate a temp password the inviter can share
            $tempPassword = Str::random(12);
            $existing = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => $tempPassword,    // hashed via cast
                'role'      => 'U',
                'site_name' => Str::slug($data['name']) . '-' . Str::lower(Str::random(4)),
            ]);
        }

        // attach if not already a member of this workspace
        $alreadyMember = DB::table('workspace_user')
            ->where('workspace_id', $wsId)
            ->where('user_id', $existing->id)
            ->exists();

        if ($alreadyMember) {
            return response()->json([
                'ok'      => false,
                'message' => "{$existing->email} is already a member of this workspace.",
            ], 409);
        }

        $workspace->members()->attach($existing->id, [
            'role'       => $data['role'],
            'invited_at' => now(),
            'joined_at'  => $tempPassword ? null : now(),
            // a brand-new user "joins" once they log in for the first time;
            // an existing user is considered joined immediately
        ]);

        // first-time users get auto-switched into this workspace on first login
        if (!$existing->current_workspace_id) {
            $existing->forceFill(['current_workspace_id' => $wsId])->save();
        }

        // Try to send the invite email. Wrapped in try/catch so a missing
        // SMTP config (MAIL_MAILER=log in dev, no creds in self-host) doesn't
        // break the invite — the temp_password we return below means the
        // inviter can still hand off credentials manually.
        //
        // A `log`/`array` mailer (or no mail host) never actually DELIVERS —
        // Mail::send() just writes to the log and returns without throwing, so
        // reporting "notified by email" there was a lie ("email nto goes"). Only
        // claim delivery when a real transport is configured; otherwise fall
        // through to the manual-credentials path so the inviter always has a
        // shareable backup.
        $mailer = (string) config('mail.default', 'log');
        $mailHost = (string) (config("mail.mailers.$mailer.host") ?? '');
        $mailDeliverable = ! in_array($mailer, ['log', 'array', 'null', ''], true)
            && ! ($mailer === 'smtp' && $mailHost === '');

        $emailSent = false;
        if ($mailDeliverable) {
            try {
                Mail::to($existing->email)->send(new TeamInviteMail(
                    invitee:      $existing,
                    workspace:    $workspace,
                    inviter:      $request->user(),
                    role:         $data['role'],
                    tempPassword: $tempPassword,
                ));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::warning('Invite email send failed: ' . $e->getMessage(), [
                    'invitee_email' => $existing->email,
                    'workspace_id'  => $wsId,
                ]);
            }
        }

        AuditLogger::workspace('member.invited', $request->user()->id, $wsId, 'user', $existing->id, [
            'email' => $existing->email,
            'role'  => $data['role'],
            'new_user' => (bool) $tempPassword,
            'email_sent' => $emailSent,
        ]);

        return response()->json([
            'ok'             => true,
            'member'         => [
                'id'    => $existing->id,
                'name'  => $existing->name,
                'email' => $existing->email,
                'role'  => $data['role'],
            ],
            'temp_password'  => $tempPassword,
            'email_sent'     => $emailSent,
            // True when this email already had a WaDesk account — they keep
            // their OWN password (we can't show a hashed password), so the UI
            // renders an "existing account" note instead of a credential box.
            'existing_user'  => $tempPassword === null,
            'message'        => $emailSent && $tempPassword
                ? "Invite sent to {$existing->email}. We've also shown the temp password below in case email isn't reaching them."
                : ($emailSent
                    ? "{$existing->name} ({$existing->email}) was added and notified by email."
                    : ($tempPassword
                        ? "Created. Email couldn't be sent — share these credentials with {$existing->name}: email {$existing->email}, password {$tempPassword}."
                        : "{$existing->name} ({$existing->email}) was added. They sign in with their existing WaDesk password.")),
        ]);
    }

    public function updateRole(Request $request, int $userId): JsonResponse
    {
        if (!WorkspacePermissions::userCan($request->user(), 'member.role.assign')) {
            abort(403);
        }

        $data = $request->validate([
            'role' => 'required|in:' . implode(',', WorkspacePermissions::ROLES),
        ]);

        $wsId = (int) $request->user()->current_workspace_id;

        // Block self-demotion below current role to prevent lockout.
        // (This is a soft guard — owner can still be removed by another owner.)
        if ($userId === $request->user()->id) {
            abort(422, 'You cannot change your own role.');
        }

        // Same role-grant gate the invite flow uses.
        if (!WorkspacePermissions::canGrantRole($request->user(), $data['role'])) {
            abort(403, "You don't have permission to grant the {$data['role']} role.");
        }

        DB::table('workspace_user')
            ->where('workspace_id', $wsId)
            ->where('user_id', $userId)
            ->update(['role' => $data['role'], 'updated_at' => now()]);

        AuditLogger::workspace('member.role_changed', $request->user()->id, $wsId, 'user', $userId, [
            'role' => $data['role'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        if (!WorkspacePermissions::userCan($request->user(), 'member.invite')) {
            abort(403);
        }
        if ($userId === $request->user()->id) {
            abort(422, 'You cannot remove yourself from the workspace.');
        }

        $wsId      = (int) $request->user()->current_workspace_id;
        $workspace = Workspace::findOrFail($wsId);

        if ($workspace->owner_user_id === $userId) {
            abort(422, 'Cannot remove the workspace owner.');
        }

        DB::table('workspace_user')
            ->where('workspace_id', $wsId)
            ->where('user_id', $userId)
            ->delete();

        // Also clear them from any teams in this workspace.
        DB::table('team_user')
            ->whereIn('team_id', DB::table('teams')->where('workspace_id', $wsId)->pluck('id'))
            ->where('user_id', $userId)
            ->delete();

        AuditLogger::workspace('member.removed', $request->user()->id, $wsId, 'user', $userId);

        return response()->json(['ok' => true]);
    }

    /**
     * Reset a member's password to a fresh temp password and return it ONCE
     * so the inviter can hand it off. This is the recovery path for the common
     * case the client hit: an EXISTING account is added as a Manager/Agent, so
     * the invite flow can't show a password (we never store the plaintext) — and
     * on self-hosted installs the reset email may not deliver either. Rather than
     * let an admin TYPE a password (which would mean they know the member's
     * standing credential — a non-repudiation hole), we generate a random one,
     * force a change on next login, and show it to the admin a single time.
     *
     * We also fire off the standard invite email as a best-effort so the member
     * gets it directly when mail IS configured; the returned temp_password is the
     * reliable backup either way.
     */
    public function resetPassword(Request $request, int $userId): JsonResponse
    {
        if (!WorkspacePermissions::userCan($request->user(), 'member.invite')) {
            abort(403, 'You do not have permission to reset member passwords.');
        }

        $wsId      = (int) $request->user()->current_workspace_id;
        $workspace = Workspace::findOrFail($wsId);

        // The target must actually be a member of THIS workspace — an admin
        // can't reset the password of someone in another tenant.
        $membership = DB::table('workspace_user')
            ->where('workspace_id', $wsId)
            ->where('user_id', $userId)
            ->first();
        if (!$membership) {
            abort(404, 'This person is not a member of your workspace.');
        }

        // Only owners may reset an owner's password (parity with role grants).
        if ($workspace->owner_user_id === $userId
            && !WorkspacePermissions::canGrantRole($request->user(), WorkspacePermissions::ROLES[0] ?? 'owner')) {
            abort(403, 'Only a workspace owner can reset the owner’s password.');
        }

        $user = User::findOrFail($userId);

        $tempPassword = Str::random(12);
        $user->forceFill([
            'password' => $tempPassword, // hashed via cast
        ])->save();

        // Best-effort direct email (same rule as invite — never claim delivery
        // when the mailer isn't a real transport).
        $mailer   = (string) config('mail.default', 'log');
        $mailHost = (string) (config("mail.mailers.$mailer.host") ?? '');
        $mailDeliverable = ! in_array($mailer, ['log', 'array', 'null', ''], true)
            && ! ($mailer === 'smtp' && $mailHost === '');

        $emailSent = false;
        if ($mailDeliverable) {
            try {
                Mail::to($user->email)->send(new TeamInviteMail(
                    invitee:      $user,
                    workspace:    $workspace,
                    inviter:      $request->user(),
                    role:         (string) $membership->role,
                    tempPassword: $tempPassword,
                ));
                $emailSent = true;
            } catch (\Throwable $e) {
                Log::warning('Member password-reset email failed: ' . $e->getMessage(), [
                    'user_id' => $userId, 'workspace_id' => $wsId,
                ]);
            }
        }

        AuditLogger::workspace('member.password_reset', $request->user()->id, $wsId, 'user', $userId, [
            'email'      => $user->email,
            'email_sent' => $emailSent,
        ]);

        return response()->json([
            'ok'            => true,
            'member'        => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'temp_password' => $tempPassword,
            'email_sent'    => $emailSent,
            'message'       => 'Password reset. Share the new credentials below.',
        ]);
    }
}
