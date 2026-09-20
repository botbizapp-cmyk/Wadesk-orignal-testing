<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Project;
use App\Models\SalesDoc;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * AI-CRM Phase 8 — unified Calendar. Aggregates every dated CRM object for the
 * current workspace into a single month grid: tasks (due_at), projects
 * (due_date), deals (expected_close_date) and sales docs (valid_until). No new
 * storage — it reads what the other modules already own.
 */
class CalendarController extends Controller
{
    public function index(Request $request)
    {
        // Anchor month (YYYY-MM), default current.
        $anchor = Carbon::hasFormat((string) $request->query('m'), 'Y-m')
            ? Carbon::createFromFormat('Y-m', $request->query('m'))->startOfMonth()
            : Carbon::now()->startOfMonth();

        $rangeStart = $anchor->copy()->startOfMonth();
        $rangeEnd = $anchor->copy()->endOfMonth();

        $events = collect();

        // Tasks
        Task::query()->forCurrentWorkspace()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$rangeStart, $rangeEnd->copy()->endOfDay()])
            ->get(['id', 'title', 'due_at', 'status'])
            ->each(function ($t) use ($events) {
                $events->push([
                    'date'  => $t->due_at->toDateString(),
                    'type'  => 'task',
                    'label' => $t->title ?: 'Task',
                    'done'  => $t->status === 'done',
                    'color' => '#0369A1',
                    'url'   => url('/tasks'),
                ]);
            });

        // Projects
        Project::query()->forCurrentWorkspace()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['id', 'name', 'due_date', 'status'])
            ->each(function ($p) use ($events) {
                $events->push([
                    'date'  => Carbon::parse($p->due_date)->toDateString(),
                    'type'  => 'project',
                    'label' => $p->name,
                    'done'  => $p->status === 'completed',
                    'color' => '#0EA5E9',
                    'url'   => url('/projects'),
                ]);
            });

        // Deals — expected close
        Deal::query()->where('workspace_id', (int) auth()->user()->current_workspace_id)
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['id', 'title', 'expected_close_date', 'status'])
            ->each(function ($d) use ($events) {
                $events->push([
                    'date'  => Carbon::parse($d->expected_close_date)->toDateString(),
                    'type'  => 'deal',
                    'label' => $d->title ?: 'Deal',
                    'done'  => in_array($d->status, ['won', 'lost'], true),
                    'color' => '#0b7a4b',
                    'url'   => url('/deals'),
                ]);
            });

        // Proposals + Estimates — valid-until deadline
        SalesDoc::query()->forCurrentWorkspace()
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['id', 'doc_type', 'number', 'title', 'valid_until', 'status', 'public_token'])
            ->each(function ($s) use ($events) {
                $events->push([
                    'date'  => Carbon::parse($s->valid_until)->toDateString(),
                    'type'  => $s->doc_type,
                    'label' => ($s->title ?: $s->number),
                    'done'  => in_array($s->status, ['accepted', 'invoiced', 'rejected'], true),
                    'color' => $s->doc_type === 'estimate' ? '#B45309' : '#6D28D9',
                    'url'   => url('/' . ($s->doc_type === 'estimate' ? 'estimates' : 'proposals')),
                ]);
            });

        $byDate = $events->groupBy('date');

        // Build the month grid (weeks of 7, Monday-start).
        $gridStart = $rangeStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $rangeEnd->copy()->endOfWeek(Carbon::SUNDAY);
        $weeks = [];
        $cursor = $gridStart->copy();
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $ds = $cursor->toDateString();
                $week[] = [
                    'date'        => $ds,
                    'day'         => $cursor->day,
                    'in_month'    => $cursor->month === $anchor->month,
                    'is_today'    => $cursor->isToday(),
                    'events'      => $byDate->get($ds, collect())->values()->all(),
                ];
                $cursor->addDay();
            }
            $weeks[] = $week;
        }

        return view('user.crm.calendar', [
            'weeks'      => $weeks,
            'monthLabel' => $anchor->format('F Y'),
            'prev'       => $anchor->copy()->subMonth()->format('Y-m'),
            'next'       => $anchor->copy()->addMonth()->format('Y-m'),
            'today'      => Carbon::now()->format('Y-m'),
            'total'      => $events->count(),
        ]);
    }
}
