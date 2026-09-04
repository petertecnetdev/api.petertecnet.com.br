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
