<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AI-CRM Phase 6 — Projects (post-sale delivery tracking). Board of In progress /
 * Overdue / Completed with progress %, plus create / update / progress / delete.
 * Plan-gated (access_sales_pipeline), role manager.
 */
class ProjectsController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function members(int $wsId): \Illuminate\Support\Collection
    {
        $ids = DB::table('workspace_user')->where('workspace_id', $wsId)->pluck('user_id')->all();
        return User::whereIn('id', $ids)->get(['id', 'name'])->values();
    }

    public function index()
    {
        $wsId = $this->wsId();
        $all  = Project::forWorkspace($wsId)->with('owner')->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(500)->get();

        $cols = ['in_progress' => [], 'overdue' => [], 'completed' => []];
        foreach ($all as $p) {
            if ($p->status === 'completed') $cols['completed'][] = $p;
            elseif ($p->isOverdue())        $cols['overdue'][]   = $p;
            else                            $cols['in_progress'][] = $p;
        }

        return view('user.projects.index', [
            'cols'      => $cols,
            'members'   => $this->members($wsId),
            'companies' => class_exists(\App\Models\Company::class)
                ? \App\Models\Company::forCurrentWorkspace()->orderBy('id')->limit(500)->get(['id', 'name']) : collect(),
            'me'        => Auth::id(),
        ]);
    }

    public function store(Request $request)
    {
        $wsId = $this->wsId();
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'company_id'  => 'nullable|integer',
            'owner_id'    => 'nullable|integer',
            'start_date'  => 'nullable|date',
            'due_date'    => 'nullable|date',
            'progress'    => 'nullable|integer|min:0|max:100',
        ]);
        $owner = $data['owner_id'] ?? Auth::id();
        if ($owner && ! $this->members($wsId)->firstWhere('id', (int) $owner)) {
            $owner = Auth::id();
        }
        Project::create([
            'workspace_id' => $wsId,
            'created_by'   => Auth::id(),
            'owner_id'     => $owner,
            'name'         => $data['name'],
            'description'  => $data['description'] ?? null,
            'company_id'   => $data['company_id'] ?? null,
            'status'       => 'in_progress',
            'progress'     => (int) ($data['progress'] ?? 0),
            'start_date'   => $data['start_date'] ?? null,
            'due_date'     => $data['due_date'] ?? null,
        ]);
        return back()->with('success', 'Project created.');
    }

    public function update(Request $request, int $id)
    {
        $p = Project::forWorkspace($this->wsId())->findOrFail($id);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'progress'    => 'sometimes|integer|min:0|max:100',
            'due_date'    => 'sometimes|nullable|date',
            'status'      => ['sometimes', Rule::in(Project::STATUSES)],
        ]);
        // Completing at 100% (or explicit status) stamps completed_at.
        if (($data['status'] ?? null) === 'completed' || (int) ($data['progress'] ?? -1) === 100) {
            $data['status'] = 'completed';
            $data['progress'] = 100;
            $data['completed_at'] = now();
        } elseif (($data['status'] ?? null) === 'in_progress') {
            $data['completed_at'] = null;
        }
        $p->fill($data)->save();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'status' => $p->status, 'progress' => $p->progress]);
        }
        return back()->with('success', 'Project updated.');
    }

    public function destroy(int $id)
    {
        Project::forWorkspace($this->wsId())->findOrFail($id)->delete();
        return back()->with('success', 'Project deleted.');
    }
}
