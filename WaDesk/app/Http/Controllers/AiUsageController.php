<?php

namespace App\Http\Controllers;

use App\Services\AiTokenMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * User-facing AI usage / spend dashboard, scoped to the current workspace.
 *
 * Reads the same `ai_token_usage` ledger the platform-admin AI dashboard uses,
 * but filtered to auth()->user()->current_workspace_id so a customer sees only
 * their own consumption: total tokens + calls, an estimated provider $ cost,
 * plan-key vs their-own-key (BYOK) split, per-provider / per-model / per-day
 * breakdowns, and how much of their plan's monthly AI cap they've burnt.
 */
class AiUsageController extends Controller
{
    /** Rough blended $/1K-token rates per provider — ESTIMATE only (mirrors admin). */
    private const RATE_PER_1K = [
        'openai'    => 0.005,
        'anthropic' => 0.006,
        'gemini'    => 0.001,
        'google'    => 0.001,
        'deepseek'  => 0.0008,
        'grok'      => 0.005,
        'mistral'   => 0.002,
    ];

    public function index(Request $request): View
    {
        $window = in_array($request->query('window'), ['7d', '30d', '90d', '1y'], true) ? $request->query('window') : '30d';
        $days   = ['7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365][$window];
        $from   = now()->subDays($days - 1)->startOfDay();

        $wsId = (int) ($request->user()->current_workspace_id ?? 0);
        $has  = Schema::hasTable('ai_token_usage');

        return view('user.ai-usage.index', [
            'window'      => $window,
            'kpis'        => $this->kpis($has, $wsId, $from),
            'byProvider'  => $this->byProvider($has, $wsId, $from),
            'daily'       => $this->daily($has, $wsId, $from, $days),
            'byModel'     => $this->byModel($has, $wsId, $from),
            'sourceSplit' => $this->sourceSplit($has, $wsId, $from),
            'cap'         => $this->monthlyCap($request),
            'voice'       => $this->voiceUsage($wsId, $from),
        ]);
    }

    /** Base query — always scoped to this workspace + window. */
    private function base(int $wsId, Carbon $from)
    {
        return DB::table('ai_token_usage')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', $from);
    }

    private function kpis(bool $has, int $wsId, Carbon $from): array
    {
        if (! $has) {
            return ['tokens' => 0, 'calls' => 0, 'admin_tokens' => 0, 'byok_tokens' => 0, 'cost' => 0.0, 'providers' => 0, 'models' => 0];
        }
        $rows = $this->base($wsId, $from);

        return [
            'tokens'       => (int) (clone $rows)->sum('total_tokens'),
            'calls'        => (int) (clone $rows)->count(),
            'admin_tokens' => (int) (clone $rows)->where('billed_against', 'admin')->sum('total_tokens'),
            'byok_tokens'  => (int) (clone $rows)->where('billed_against', 'workspace')->sum('total_tokens'),
            'cost'         => $this->estimateCost($wsId, $from),
            'providers'    => (int) (clone $rows)->distinct()->count('provider'),
            'models'       => (int) (clone $rows)->distinct()->count('model'),
        ];
    }

    /** Estimated $ spend for THIS workspace, summed per provider at its blended rate. */
    private function estimateCost(int $wsId, Carbon $from): float
    {
        $cost = 0.0;
        $rows = $this->base($wsId, $from)
            ->select('provider', DB::raw('SUM(total_tokens) as t'))->groupBy('provider')->get();
        foreach ($rows as $r) {
            $rate = self::RATE_PER_1K[strtolower((string) $r->provider)] ?? 0.004;
            $cost += ((int) $r->t / 1000) * $rate;
        }

        return round($cost, 2);
    }

    private function byProvider(bool $has, int $wsId, Carbon $from): array
    {
        if (! $has) {
            return [];
        }

        return $this->base($wsId, $from)
            ->select('provider', DB::raw('SUM(total_tokens) as tokens'), DB::raw('COUNT(*) as calls'))
            ->groupBy('provider')->orderByDesc('tokens')->get()
            ->map(fn ($r) => ['provider' => $r->provider ?: 'unknown', 'tokens' => (int) $r->tokens, 'calls' => (int) $r->calls])
            ->all();
    }

    private function daily(bool $has, int $wsId, Carbon $from, int $days): array
    {
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $series[now()->subDays($days - 1 - $i)->toDateString()] = 0;
        }
        if ($has) {
            $this->base($wsId, $from)
                ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(total_tokens) as t'))
                ->groupBy('d')->get()
                ->each(function ($r) use (&$series) {
                    if (isset($series[$r->d])) {
                        $series[$r->d] = (int) $r->t;
                    }
                });
        }

        return $series;
    }

    private function byModel(bool $has, int $wsId, Carbon $from): array
    {
        if (! $has) {
            return [];
        }

        return $this->base($wsId, $from)
            ->select('model', 'provider', DB::raw('SUM(total_tokens) as tokens'), DB::raw('COUNT(*) as calls'),
                DB::raw('SUM(prompt_tokens) as pt'), DB::raw('SUM(completion_tokens) as ct'))
            ->groupBy('model', 'provider')->orderByDesc('tokens')->limit(15)->get()
            ->map(fn ($r) => [
                'model'    => $r->model ?: '—',
                'provider' => $r->provider,
                'tokens'   => (int) $r->tokens,
                'calls'    => (int) $r->calls,
                'prompt'   => (int) $r->pt,
                'completion' => (int) $r->ct,
            ])->all();
    }

    private function sourceSplit(bool $has, int $wsId, Carbon $from): array
    {
        if (! $has) {
            return ['admin' => 0, 'workspace' => 0];
        }
        $rows = $this->base($wsId, $from)
            ->select('billed_against', DB::raw('SUM(total_tokens) as t'))->groupBy('billed_against')->pluck('t', 'billed_against');

        return ['admin' => (int) ($rows['admin'] ?? 0), 'workspace' => (int) ($rows['workspace'] ?? 0)];
    }

    /** Plan monthly AI-token cap — used vs limit (admin keys only). */
    private function monthlyCap(Request $request): array
    {
        $ws = $request->user()?->currentWorkspace;
        if (! $ws) {
            return ['used' => 0, 'limit' => null, 'pct' => 0];
        }
        $used  = AiTokenMeter::usedThisMonth($ws);
        $limit = $ws->effectiveLimit('ai_token_limit_monthly', null);
        $pct   = ($limit && (int) $limit > 0) ? min(100, (int) round($used / (int) $limit * 100)) : 0;

        return ['used' => (int) $used, 'limit' => $limit === null ? null : (int) $limit, 'pct' => $pct];
    }

    /** Voice (TTS/STT) + AI-call usage for this workspace, if tracked. */
    private function voiceUsage(int $wsId, Carbon $from): array
    {
        $out = ['voice_rows' => 0, 'calls' => 0];
        try {
            if (Schema::hasTable('ai_voice_usage_daily')) {
                $out['voice_rows'] = (int) DB::table('ai_voice_usage_daily')->where('workspace_id', $wsId)->count();
            }
            if (Schema::hasTable('ai_call_logs')) {
                $q = DB::table('ai_call_logs')->where('created_at', '>=', $from);
                if (Schema::hasColumn('ai_call_logs', 'workspace_id')) {
                    $q->where('workspace_id', $wsId);
                }
                $out['calls'] = (int) $q->count();
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
