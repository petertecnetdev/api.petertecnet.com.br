<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AgentControlRepositoryService
{
    private string $repository;
    private string $branch;
    private int $timeout;

    public function __construct()
    {
        $this->repository = (string) config('services.agent_chat.repository', 'petertecnetdev/petertecnet.com.br');
        $this->branch = (string) config('services.agent_chat.branch', 'main');
        $this->timeout = max(3, (int) config('services.agent_chat.timeout', 12));
    }

    public function overview(bool $fresh = false): array
    {
        $cacheKey = 'agent-control:overview:' . sha1($this->repository . ':' . $this->branch);

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(20), function () {
            $registry = $this->jsonFile('.agents/AGENTS_REGISTRY.json') ?: ['agents' => [], 'applications' => []];
            $agents = array_values((array) ($registry['agents'] ?? []));
            $states = $this->loadStates($agents);
            $tasks = $this->loadTasks();

            [$tasks, $pendingTaskIds] = $this->overlayPendingOperations($tasks);

            $stateByAgent = collect($states)->keyBy('agent_id');
            $now = CarbonImmutable::now('America/Sao_Paulo');

            $agentRows = array_map(function (array $agent) use ($stateByAgent, $now) {
                $id = (string) ($agent['id'] ?? '');
                $state = (array) ($stateByAgent->get($id) ?? []);
                $lastSeen = $this->parseDate($state['last_seen_at'] ?? null);

                $presence = 'NEVER';
                if ($lastSeen) {
                    $minutes = $lastSeen->diffInMinutes($now);
                    $presence = $minutes <= 10 ? 'ACTIVE' : ($minutes <= 60 ? 'RECENT' : 'STALE');
                }

                return [
                    ...$agent,
                    'state' => $state,
                    'presence' => $presence,
                    'last_seen_at' => $state['last_seen_at'] ?? null,
                    'last_read_at' => $state['last_read_at'] ?? null,
                    'current_task_id' => $state['current_task_id'] ?? null,
                    'runtime_status' => $state['status'] ?? 'WAITING',
                    'checkpoint' => $state['checkpoint'] ?? null,
                    'next_action' => $state['next_action'] ?? null,
                    'last_commit' => $state['last_commit'] ?? null,
                    'last_error' => $state['last_error'] ?? null,
                ];
            }, $agents);

            $taskRows = array_map(function (array $task) use ($now, $pendingTaskIds) {
                $lock = (array) ($task['lock'] ?? []);
                $expires = $this->parseDate($lock['expires_at'] ?? null);
                $lockExpired = $expires ? $expires->isPast() : false;

                return [
                    ...$task,
                    'lock_expired' => $lockExpired,
                    'sync_status' => in_array($task['task_id'] ?? '', $pendingTaskIds, true) ? 'pending' : 'synced',
                ];
            }, $tasks);

            usort($taskRows, fn (array $a, array $b) => $this->taskSort($a, $b));

            $metrics = [
                'agents_total' => count($agentRows),
                'agents_active' => count(array_filter($agentRows, fn ($a) => ($a['presence'] ?? '') === 'ACTIVE')),
                'tasks_total' => count($taskRows),
                'tasks_running' => count(array_filter($taskRows, fn ($t) => ($t['status'] ?? '') === 'RUNNING')),
                'tasks_blocked' => count(array_filter($taskRows, fn ($t) => in_array(($t['status'] ?? ''), ['BLOCKED', 'NEEDS_OWNER_DECISION'], true))),
                'tasks_review' => count(array_filter($taskRows, fn ($t) => ($t['status'] ?? '') === 'REVIEW')),
                'tasks_done' => count(array_filter($taskRows, fn ($t) => ($t['status'] ?? '') === 'DONE')),
            ];

            return [
                'registry' => $registry,
                'agents' => $agentRows,
                'tasks' => $taskRows,
                'metrics' => $metrics,
                'decisions' => $this->textFile('.agents/DECISIONS.md'),
                'blockers' => $this->textFile('.agents/BLOCKERS.md'),
                'protocol_url' => $this->githubUrl('.agents/CONTROL_PROTOCOL.md'),
                'bootstrap_url' => $this->githubUrl('.agents/TASK_BOOTSTRAP.md'),
                'repository' => $this->repository,
                'branch' => $this->branch,
                'synced_at' => now()->toIso8601String(),
            ];
        });
    }

    public function createTask(array $data, ?object $user = null): array
    {
        $registry = $this->jsonFile('.agents/AGENTS_REGISTRY.json') ?: [];
        $validAgents = collect((array) ($registry['agents'] ?? []))->pluck('id')->filter()->values()->all();
        $validApps = array_values((array) ($registry['applications'] ?? []));

        $agent = trim((string) ($data['assigned_agent_id'] ?? '')) ?: null;
        if ($agent && ! in_array($agent, $validAgents, true)) {
            throw new RuntimeException('O agente informado não existe no registro central.');
        }

        $context = trim((string) ($data['application_context'] ?? '')) ?: null;
        if ($context && ! in_array($context, $validApps, true)) {
            throw new RuntimeException('O contexto informado não existe no registro central.');
        }

        $priority = strtoupper((string) ($data['priority'] ?? 'NORMAL'));
        if (! in_array($priority, ['CRITICAL', 'HIGH', 'NORMAL', 'LOW'], true)) {
            $priority = 'NORMAL';
        }

        $tags = array_values(array_unique(array_filter(array_map(
            fn ($tag) => strtolower(trim((string) $tag)),
            (array) ($data['tags'] ?? [])
        ))));

        $taskId = 'TASK-' . now('America/Sao_Paulo')->format('Ymd') . '-' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 6));
        $operationId = (string) Str::uuid();
        $createdAt = now()->toIso8601String();
        $reviewRequired = (bool) ($data['review_required'] ?? false)
            || $priority === 'CRITICAL'
            || count(array_intersect($tags, ['security', 'payments', 'production', 'critical'])) > 0;

        $reviewer = in_array('security', $tags, true) ? 'NP04' : ($reviewRequired ? 'NP03' : null);

        $task = [
            'schema_version' => 1,
            'task_id' => $taskId,
            'title' => trim((string) $data['title']),
            'description' => trim((string) ($data['description'] ?? '')),
            'application_context' => $context,
            'repository' => trim((string) ($data['repository'] ?? '')) ?: null,
            'priority' => $priority,
            'status' => $agent ? 'ASSIGNED' : 'NEW',
            'assigned_agent_id' => $agent,
            'owner_agent_id' => null,
            'created_by' => 'OWNER',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'started_at' => null,
            'completed_at' => null,
            'reviewed_at' => null,
            'due_at' => $data['due_at'] ?? null,
            'lock' => [
                'owner_agent_id' => null,
                'acquired_at' => null,
                'expires_at' => null,
            ],
            'delegation_count' => 0,
            'max_delegations' => 2,
            'checkpoint' => null,
            'next_action' => $agent ? "Agente {$agent} deve ler, confirmar recebimento e assumir a tarefa." : 'Aguardando atribuição.',
            'blocker' => null,
            'tags' => $tags,
            'evidence' => [],
            'review' => [
                'required' => $reviewRequired,
                'reviewer_agent_id' => $reviewer,
                'status' => $reviewRequired ? 'PENDING' : 'NOT_REQUIRED',
                'notes' => null,
            ],
            'history' => [[
                'operation_id' => $operationId,
                'at' => $createdAt,
                'actor' => 'OWNER',
                'event' => 'CREATED',
                'message' => $this->ownerLabel($user) . ' criou a tarefa pelo Admin Center.',
            ]],
            'applied_operation_ids' => [$operationId],
            'version' => 1,
        ];

        $operation = [
            'id' => $operationId,
            'type' => 'task_create',
            'task_id' => $taskId,
            'task' => $task,
            'created_at' => $createdAt,
        ];

        $this->appendOutbox($operation);
        Cache::forget('agent-control:overview:' . sha1($this->repository . ':' . $this->branch));

        return [
            'task' => [...$task, 'sync_status' => 'pending'],
            'operation_id' => $operationId,
            'delivery_mode' => 'github_actions_sync',
        ];
    }

    public function updateTask(string $taskId, array $data, ?object $user = null): array
    {
        if (! preg_match('/^TASK-[0-9]{8}-[A-Z0-9]{6}$/', $taskId)) {
            throw new RuntimeException('Identificador de tarefa inválido.');
        }

        $allowed = [
            'title', 'description', 'application_context', 'repository', 'priority',
            'status', 'assigned_agent_id', 'due_at', 'next_action', 'blocker',
        ];

        $patch = Arr::only($data, $allowed);

        if (isset($patch['priority'])) {
            $patch['priority'] = strtoupper((string) $patch['priority']);
            if (! in_array($patch['priority'], ['CRITICAL', 'HIGH', 'NORMAL', 'LOW'], true)) {
                throw new RuntimeException('Prioridade inválida.');
            }
        }

        if (isset($patch['status'])) {
            $patch['status'] = strtoupper((string) $patch['status']);
            $valid = ['NEW', 'ASSIGNED', 'RUNNING', 'WAITING', 'REVIEW', 'DONE', 'BLOCKED', 'NEEDS_OWNER_DECISION', 'CANCELLED'];
            if (! in_array($patch['status'], $valid, true)) {
                throw new RuntimeException('Status inválido.');
            }
        }

        if ($patch === []) {
            throw new RuntimeException('Nenhuma alteração válida foi informada.');
        }

        $operationId = (string) Str::uuid();
        $operation = [
            'id' => $operationId,
            'type' => 'task_patch',
            'task_id' => $taskId,
            'patch' => $patch,
            'history_event' => [
                'operation_id' => $operationId,
                'at' => now()->toIso8601String(),
                'actor' => 'OWNER',
                'event' => 'OWNER_UPDATE',
                'message' => $this->ownerLabel($user) . ' atualizou a tarefa pelo Admin Center.',
            ],
            'created_at' => now()->toIso8601String(),
        ];

        $this->appendOutbox($operation);
        Cache::forget('agent-control:overview:' . sha1($this->repository . ':' . $this->branch));

        return [
            'task_id' => $taskId,
            'patch' => $patch,
            'operation_id' => $operationId,
            'delivery_mode' => 'github_actions_sync',
        ];
    }

    public function syncFeed(): array
    {
        return $this->pruneOutboxAgainstRepository();
    }

    private function loadStates(array $agents): array
    {
        $paths = [];
        foreach ($agents as $agent) {
            $id = (string) ($agent['id'] ?? '');
            if ($id !== '') {
                $paths[$id] = ".agents/state/{$id}.json";
            }
        }

        if ($paths === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($paths) {
            $requests = [];
            foreach ($paths as $id => $path) {
                $requests[$id] = $pool->as($id)->timeout($this->timeout)->get($this->rawUrl($path));
            }
            return $requests;
        });

        $states = [];
        foreach ($paths as $id => $path) {
            $response = $responses[$id] ?? null;
            if ($response && $response->successful()) {
                $decoded = json_decode((string) $response->body(), true);
                if (is_array($decoded)) {
                    $states[] = $decoded;
                }
            }
        }

        return $states;
    }

    private function loadTasks(): array
    {
        $paths = Cache::remember(
            'agent-control:task-paths:' . sha1($this->repository . ':' . $this->branch),
            now()->addSeconds(60),
            function () {
                $response = Http::acceptJson()
                    ->timeout($this->timeout)
                    ->get("https://api.github.com/repos/{$this->repository}/contents/.agents/tasks", ['ref' => $this->branch]);

                if (! $response->successful()) {
                    return [];
                }

                return collect((array) $response->json())
                    ->filter(fn ($item) => is_array($item) && preg_match('/^TASK-.*\.json$/', (string) ($item['name'] ?? '')))
                    ->pluck('path')
                    ->values()
                    ->all();
            }
        );

        if ($paths === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($paths) {
            $requests = [];
            foreach ($paths as $index => $path) {
                $requests["task_{$index}"] = $pool->as("task_{$index}")->timeout($this->timeout)->get($this->rawUrl((string) $path));
            }
            return $requests;
        });

        $tasks = [];
        foreach ($paths as $index => $path) {
            $response = $responses["task_{$index}"] ?? null;
            if ($response && $response->successful()) {
                $decoded = json_decode((string) $response->body(), true);
                if (is_array($decoded) && isset($decoded['task_id'])) {
                    $tasks[] = $decoded;
                }
            }
        }

        return $tasks;
    }

    private function overlayPendingOperations(array $tasks): array
    {
        $operations = $this->readOutbox();
        $byId = [];
        foreach ($tasks as $task) {
            if (isset($task['task_id'])) {
                $byId[(string) $task['task_id']] = $task;
            }
        }

        $pendingTaskIds = [];

        foreach ($operations as $operation) {
            $type = (string) ($operation['type'] ?? '');
            $taskId = (string) ($operation['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }

            $pendingTaskIds[] = $taskId;

            if ($type === 'task_create' && is_array($operation['task'] ?? null)) {
                $byId[$taskId] = $operation['task'];
                continue;
            }

            if ($type === 'task_patch' && isset($byId[$taskId])) {
                foreach ((array) ($operation['patch'] ?? []) as $key => $value) {
                    $byId[$taskId][$key] = $value;
                }
                $byId[$taskId]['updated_at'] = $operation['created_at'] ?? now()->toIso8601String();
            }
        }

        return [array_values($byId), array_values(array_unique($pendingTaskIds))];
    }

    private function pruneOutboxAgainstRepository(): array
    {
        return $this->mutateOutbox(function (array $operations) {
            $remaining = [];

            foreach ($operations as $operation) {
                $taskId = (string) ($operation['task_id'] ?? '');
                $operationId = (string) ($operation['id'] ?? '');

                if ($taskId === '' || $operationId === '') {
                    continue;
                }

                $task = $this->jsonFile(".agents/tasks/{$taskId}.json", false);
                $applied = is_array($task) ? (array) ($task['applied_operation_ids'] ?? []) : [];

                if (in_array($operationId, $applied, true)) {
                    continue;
                }

                $remaining[] = $operation;
            }

            return $remaining;
        });
    }

    private function appendOutbox(array $operation): void
    {
        $this->mutateOutbox(function (array $operations) use ($operation) {
            $operations[] = $operation;
            return $operations;
        });
    }

    private function readOutbox(): array
    {
        $path = $this->outboxPath();
        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Não foi possível ler a fila de controle dos agentes.');
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('Não foi possível bloquear a fila de controle para leitura.');
            }
            $raw = stream_get_contents($handle);
            $decoded = json_decode($raw ?: '[]', true);
            return is_array($decoded) ? array_values($decoded) : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function mutateOutbox(callable $callback): array
    {
        $path = $this->outboxPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a fila de controle dos agentes.');
        }

        $handle = @fopen($path, 'c+');
        if (! $handle) {
            throw new RuntimeException('Não foi possível abrir a fila de controle dos agentes.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Não foi possível bloquear a fila de controle.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = json_decode($raw ?: '[]', true);
            $operations = is_array($decoded) ? array_values($decoded) : [];
            $next = $callback($operations);

            if (! is_array($next)) {
                throw new RuntimeException('Atualização inválida da fila de controle.');
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(array_values($next), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);

            return array_values($next);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function jsonFile(string $path, bool $cache = true): ?array
    {
        $loader = function () use ($path) {
            $response = Http::timeout($this->timeout)->get($this->rawUrl($path));
            if (! $response->successful()) {
                return null;
            }
            $decoded = json_decode((string) $response->body(), true);
            return is_array($decoded) ? $decoded : null;
        };

        if (! $cache) {
            return $loader();
        }

        return Cache::remember(
            'agent-control:file:' . sha1($this->repository . ':' . $this->branch . ':' . $path),
            now()->addSeconds(20),
            $loader
        );
    }

    private function textFile(string $path): string
    {
        return Cache::remember(
            'agent-control:text:' . sha1($this->repository . ':' . $this->branch . ':' . $path),
            now()->addSeconds(30),
            function () use ($path) {
                $response = Http::timeout($this->timeout)->get($this->rawUrl($path));
                return $response->successful() ? (string) $response->body() : '';
            }
        );
    }

    private function rawUrl(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
        return "https://raw.githubusercontent.com/{$this->repository}/" . rawurlencode($this->branch) . "/{$encoded}";
    }

    private function githubUrl(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
        return "https://github.com/{$this->repository}/blob/" . rawurlencode($this->branch) . "/{$encoded}";
    }

    private function outboxPath(): string
    {
        return storage_path('app/agent-control-outbox.json');
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }
    }

    private function taskSort(array $a, array $b): int
    {
        $statusWeight = [
            'RUNNING' => 0,
            'BLOCKED' => 1,
            'NEEDS_OWNER_DECISION' => 1,
            'REVIEW' => 2,
            'ASSIGNED' => 3,
            'NEW' => 4,
            'WAITING' => 5,
            'DONE' => 8,
            'CANCELLED' => 9,
        ];
        $priorityWeight = ['CRITICAL' => 0, 'HIGH' => 1, 'NORMAL' => 2, 'LOW' => 3];

        $left = [
            $statusWeight[$a['status'] ?? 'NEW'] ?? 6,
            $priorityWeight[$a['priority'] ?? 'NORMAL'] ?? 2,
            (string) ($a['created_at'] ?? ''),
        ];
        $right = [
            $statusWeight[$b['status'] ?? 'NEW'] ?? 6,
            $priorityWeight[$b['priority'] ?? 'NORMAL'] ?? 2,
            (string) ($b['created_at'] ?? ''),
        ];

        return $left <=> $right;
    }

    private function ownerLabel(?object $user): string
    {
        if (! $user) {
            return 'OWNER';
        }

        $name = trim(implode(' ', array_filter([
            (string) ($user->first_name ?? ''),
            (string) ($user->last_name ?? ''),
        ])));

        return $name ?: 'OWNER';
    }
}
