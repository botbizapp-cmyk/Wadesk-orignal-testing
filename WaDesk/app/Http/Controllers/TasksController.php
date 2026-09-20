<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AI-CRM Phase 3 — first-class tasks. "My Tasks" board (overdue / today /
 * upcoming / no-date) plus create / complete / edit / delete. Plan-gated
 * (access_sales_pipeline — same as Deals/Companies), role manager.
 */
class TasksController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Workspace member users for the assignee picker. */
    private function members(int $wsId): \Illuminate\Support\Collection
    {
        $ids = DB::table('workspace_user')->where('workspace_id', $wsId)->pluck('user_id')->all();
        return User::whereIn('id', $ids)->get(['id', 'name'])->values();
    }

    public function index(Request $request)
    {
        $wsId  = $this->wsId();
        $me    = Auth::id();
        $scope = $request->query('scope') === 'all' ? 'all' : 'mine';

        $q = Task::forWorkspace($wsId)->where('status', 'open');
        if ($scope === 'mine') {
            $q->where(fn ($w) => $w->where('assignee_id', $me)->orWhereNull('assignee_id'));
        }
        $open = $q->with('assignee')->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(500)->get();

        $now = now();
        $buckets = ['overdue' => [], 'today' => [], 'upcoming' => [], 'no_date' => []];
        foreach ($open as $t) {
            if (! $t->due_at)                     $buckets['no_date'][]  = $t;
            elseif ($t->due_at->isPast())         $buckets['overdue'][]  = $t;
            elseif ($t->due_at->isSameDay($now))  $buckets['today'][]    = $t;
            else                                  $buckets['upcoming'][] = $t;
        }

        $doneRecent = Task::forWorkspace($wsId)->where('status', 'done')
            ->orderByDesc('done_at')->limit(20)->with('assignee')->get();

        return view('user.tasks.index', [
            'buckets'    => $buckets,
            'openCount'  => $open->count(),
            'doneRecent' => $doneRecent,
            'members'    => $this->members($wsId),
            'scope'      => $scope,
            'me'         => $me,
        ]);
    }

    public function store(Request $request)
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'notes'        => 'nullable|string|max:5000',
            'priority'     => ['nullable', Rule::in(Task::PRIORITIES)],
            'due_at'       => 'nullable|date',
            'assignee_id'  => 'nullable|integer',
            'related_type' => ['nullable', Rule::in(Task::RELATED)],
            'related_id'   => 'nullable|integer',
        ]);

        // Assignee must be a workspace member; default to the creator.
        $assignee = $data['assignee_id'] ?? Auth::id();
        if ($assignee && ! $this->members($wsId)->firstWhere('id', (int) $assignee)) {
            $assignee = Auth::id();
        }

        Task::create([
            'workspace_id' => $wsId,
            'created_by'   => Auth::id(),
            'assignee_id'  => $assignee,
            'title'        => $data['title'],
            'notes'        => $data['notes'] ?? null,
            'priority'     => $data['priority'] ?? 'medium',
            'status'       => 'open',
            'related_type' => $data['related_type'] ?? null,
            'related_id'   => $data['related_id'] ?? null,
            'due_at'       => $data['due_at'] ?? null,
        ]);

        return back()->with('success', 'Task created.');
    }

    public function complete(Request $request, int $id)
    {
        $task = Task::forWorkspace($this->wsId())->findOrFail($id);
        $done = $task->status !== 'done';
        $task->forceFill([
            'status'  => $done ? 'done' : 'open',
            'done_at' => $done ? now() : null,
        ])->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'status' => $task->status]);
        }
        return back()->with('success', $done ? 'Task completed.' : 'Task reopened.');
    }

    public function update(Request $request, int $id)
    {
        $task = Task::forWorkspace($this->wsId())->findOrFail($id);
        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'notes'       => 'sometimes|nullable|string|max:5000',
            'priority'    => ['sometimes', Rule::in(Task::PRIORITIES)],
            'due_at'      => 'sometimes|nullable|date',
            'assignee_id' => 'sometimes|nullable|integer',
        ]);
        if (array_key_exists('assignee_id', $data) && $data['assignee_id']
            && ! $this->members($this->wsId())->firstWhere('id', (int) $data['assignee_id'])) {
            unset($data['assignee_id']); // ignore a non-member assignee
        }
        $task->fill($data)->save();
        return back()->with('success', 'Task updated.');
    }

    public function destroy(int $id)
    {
        Task::forWorkspace($this->wsId())->findOrFail($id)->delete();
        return back()->with('success', 'Task deleted.');
    }
}
