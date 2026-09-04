<?php

namespace App\Services;

use App\Models\CognitiveAgent;
use App\Models\CognitiveExperiment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CognitiveQueryService
{
    public function paginateAgents(int $perPage = 50): LengthAwarePaginator
    {
        return CognitiveAgent::query()->latest()->paginate($this->perPage($perPage, 50));
    }

    public function createAgent(array $data, User $actor): CognitiveAgent
    {
        return CognitiveAgent::create(array_merge($data, [
            'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'learning_enabled' => $data['learning_enabled'] ?? true,
            'metadata' => ['created_by' => $actor->id, 'schema_version' => '0.1.0'],
        ]));
    }

    public function agentSummary(CognitiveAgent $agent): CognitiveAgent
    {
        return $agent->loadCount(['observations', 'memories', 'beliefs', 'goals', 'experimentRuns']);
    }

    public function dashboard(CognitiveAgent $agent): array
    {
        $now = now();
        $since24h = $now->copy()->subDay();
        $since7d = $now->copy()->subDays(7);
        $since14d = $now->copy()->subDays(13)->startOfDay();

        $memoryBase = $agent->memories();
        $beliefBase = $agent->beliefs();
        $observationBase = $agent->observations();
        $learningBase = $agent->learningEvents();
        $runBase = $agent->experimentRuns();

        $beliefCounts = $beliefBase->clone()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $runCounts = $runBase->clone()
            ->selectRaw('result, COUNT(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $learningTimeline = $learningBase->clone()
            ->where('created_at', '>=', $since14d)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->pluck('total', 'day');

        $observationTimeline = $observationBase->clone()
            ->where('created_at', '>=', $since14d)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->pluck('total', 'day');

        $timeline = collect(range(13, 0))->map(function (int $offset) use ($learningTimeline, $observationTimeline, $now) {
            $day = $now->copy()->subDays($offset)->toDateString();

            return [
                'day' => $day,
                'observations' => (int) ($observationTimeline[$day] ?? 0),
                'learning_events' => (int) ($learningTimeline[$day] ?? 0),
            ];
        })->values();

        $totalBeliefs = (int) $beliefBase->clone()->count();
        $totalMemories = (int) $memoryBase->clone()->count();
        $activeMemories = (int) $memoryBase->clone()->where('active', true)->count();
        $totalContradictions = (int) $beliefBase->clone()->sum('contradiction_count');
        $totalEvidence = (int) $beliefBase->clone()->sum('evidence_count');

        return [
            'system' => [
                'enabled' => (bool) config('cognition.enabled'),
                'auto_learn' => (bool) config('cognition.auto_learn'),
                'allow_self_generated_goals' => (bool) config('cognition.allow_self_generated_goals'),
                'memory_threshold' => (float) config('cognition.memory_threshold'),
                'queue' => (string) config('cognition.queue'),
                'environment' => app()->environment(),
            ],
            'agent' => $this->agentSummary($agent),
            'summary' => [
                'observations' => (int) $observationBase->clone()->count(),
                'observations_24h' => (int) $observationBase->clone()->where('created_at', '>=', $since24h)->count(),
                'observations_7d' => (int) $observationBase->clone()->where('created_at', '>=', $since7d)->count(),
                'high_salience_observations' => (int) $observationBase->clone()->where('salience', '>=', 0.75)->count(),
                'memories' => $totalMemories,
                'active_memories' => $activeMemories,
                'memory_confidence_avg' => round((float) ($memoryBase->clone()->where('active', true)->avg('confidence') ?? 0), 4),
                'memory_importance_avg' => round((float) ($memoryBase->clone()->where('active', true)->avg('importance') ?? 0), 4),
                'memory_reinforcements' => (int) $memoryBase->clone()->sum('reinforcement_count'),
                'beliefs' => $totalBeliefs,
                'active_beliefs' => (int) ($beliefCounts['active'] ?? 0),
                'uncertain_beliefs' => (int) ($beliefCounts['uncertain'] ?? 0),
                'retracted_beliefs' => (int) ($beliefCounts['retracted'] ?? 0),
                'belief_confidence_avg' => round((float) ($beliefBase->clone()->where('status', '!=', 'retracted')->avg('confidence') ?? 0), 4),
                'evidence_count' => $totalEvidence,
                'contradiction_count' => $totalContradictions,
                'contradiction_rate' => $totalEvidence + $totalContradictions > 0
                    ? round($totalContradictions / ($totalEvidence + $totalContradictions), 4)
                    : 0.0,
                'learning_events' => (int) $learningBase->clone()->count(),
                'learning_events_24h' => (int) $learningBase->clone()->where('created_at', '>=', $since24h)->count(),
                'goals_active' => (int) $agent->goals()->where('status', 'active')->count(),
                'experiment_runs' => (int) $runBase->clone()->count(),
                'experiment_passed' => (int) ($runCounts['pass'] ?? 0),
                'experiment_failed' => (int) ($runCounts['fail'] ?? 0),
                'experiment_inconclusive' => (int) ($runCounts['inconclusive'] ?? 0),
                'experiment_score_avg' => round((float) ($runBase->clone()->whereNotNull('score')->avg('score') ?? 0), 4),
            ],
            'timeline' => $timeline,
            'recent' => [
                'learning_events' => $learningBase->clone()->latest()->limit(12)->get(),
                'beliefs' => $beliefBase->clone()->where('status', '!=', 'retracted')->orderByDesc('last_evidence_at')->orderByDesc('confidence')->limit(10)->get(),
                'memories' => $memoryBase->clone()->where('active', true)->orderByDesc('last_reinforced_at')->orderByDesc('created_at')->limit(10)->get(),
                'observations' => $observationBase->clone()->latest()->limit(10)->get(),
                'state' => $agent->stateSnapshots()->latest('captured_at')->first(),
            ],
        ];
    }

    public function updateAgent(CognitiveAgent $agent, array $data): CognitiveAgent
    {
        $agent->update($data);
        return $agent->fresh();
    }

    public function paginateObservations(CognitiveAgent $agent, int $perPage = 30): LengthAwarePaginator
    {
        return $agent->observations()->latest()->paginate($this->perPage($perPage));
    }

    public function paginateMemories(CognitiveAgent $agent, int $perPage = 30, ?string $type = null, bool $includeInactive = false): LengthAwarePaginator
    {
        $query = $agent->memories()->latest();
        if ($type !== null && $type !== '') $query->where('memory_type', $type);
        if (! $includeInactive) $query->where('active', true);
        return $query->paginate($this->perPage($perPage));
    }

    public function paginateBeliefs(CognitiveAgent $agent, int $perPage = 30, ?string $status = null): LengthAwarePaginator
    {
        $query = $agent->beliefs()->orderByDesc('confidence')->latest('last_evidence_at');
        if ($status !== null && $status !== '') $query->where('status', $status);
        return $query->paginate($this->perPage($perPage));
    }

    public function paginateGoals(CognitiveAgent $agent, int $perPage = 50): LengthAwarePaginator
    {
        return $agent->goals()->orderByDesc('priority')->latest()->paginate($this->perPage($perPage, 50));
    }

    public function paginateLearningEvents(CognitiveAgent $agent, int $perPage = 30): LengthAwarePaginator
    {
        return $agent->learningEvents()->latest()->paginate($this->perPage($perPage));
    }

    public function experiments(): Collection
    {
        return CognitiveExperiment::query()->where('enabled', true)->orderBy('dimension')->get();
    }

    public function paginateExperimentRuns(CognitiveAgent $agent, int $perPage = 50): LengthAwarePaginator
    {
        return $agent->experimentRuns()->with('experiment')->latest()->paginate($this->perPage($perPage, 50));
    }

    private function perPage(int $requested, int $default = 30): int
    {
        if ($requested < 1) return $default;
        return min(100, $requested);
    }
}
