<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentControlRepositoryService;
use App\Services\AgentChatGithubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AgentControlController extends Controller
{
    public function overview(AgentControlRepositoryService $control): JsonResponse
    {
        try {
            return response()->json($control->overview());
        } catch (Throwable $exception) {
            Log::error('Agent control overview failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível carregar o controle dos agentes.',
                'agents' => [],
                'tasks' => [],
                'metrics' => [],
            ], 502);
        }
    }

    public function storeTask(Request $request, AgentControlRepositoryService $control, AgentChatGithubService $chat): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:8000'],
            'application_context' => ['nullable', 'string', 'max:50'],
            'repository' => ['nullable', 'string', 'max:160'],
            'priority' => ['nullable', 'string', 'in:CRITICAL,HIGH,NORMAL,LOW'],
            'assigned_agent_id' => ['nullable', 'string', 'max:20'],
            'due_at' => ['nullable', 'date'],
            'review_required' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
        ]);

        try {
            $result = $control->createTask($validated, $request->user());
            $task = (array) ($result['task'] ?? []);

            try {
                $chatResult = $chat->append([
                    'message' => trim((string) ($task['description'] ?? '')) ?: ('Nova tarefa: ' . ($task['title'] ?? $task['task_id'] ?? '')),
                    'to' => ! empty($task['assigned_agent_id']) ? '@' . $task['assigned_agent_id'] : '@todos',
                    'subject' => (string) ($task['title'] ?? 'Nova tarefa'),
                    'task_id' => $task['task_id'] ?? null,
                    'application_context' => $task['application_context'] ?? null,
                    'priority' => $task['priority'] ?? 'NORMAL',
                    'type' => 'REQUEST',
                ], $request->user());

                $result['chat_message'] = $chatResult['message'] ?? null;
            } catch (Throwable $chatException) {
                Log::warning('Task created but Agent Chat notification failed.', [
                    'task_id' => $task['task_id'] ?? null,
                    'message' => $chatException->getMessage(),
                ]);
                $result['chat_message'] = null;
            }

            return response()->json($result, 201);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function updateTask(string $task, Request $request, AgentControlRepositoryService $control): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'application_context' => ['sometimes', 'nullable', 'string', 'max:50'],
            'repository' => ['sometimes', 'nullable', 'string', 'max:160'],
            'priority' => ['sometimes', 'string', 'in:CRITICAL,HIGH,NORMAL,LOW'],
            'status' => ['sometimes', 'string', 'in:NEW,ASSIGNED,RUNNING,WAITING,REVIEW,DONE,BLOCKED,NEEDS_OWNER_DECISION,CANCELLED'],
            'assigned_agent_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'blocker' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        try {
            return response()->json($control->updateTask($task, $validated, $request->user()), 202);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function syncFeed(AgentControlRepositoryService $control): JsonResponse
    {
        try {
            return response()->json([
                'operations' => $control->syncFeed(),
                'generated_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Agent control sync feed failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'operations' => [],
                'message' => 'Não foi possível carregar a fila de controle.',
            ], 500);
        }
    }
}
