<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentChatGithubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AgentChatController extends Controller
{
    public function index(AgentChatGithubService $chat): JsonResponse
    {
        try {
            return response()->json($chat->snapshot());
        } catch (Throwable $exception) {
            Log::warning('Agent Chat read failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível sincronizar o Agent Chat com o GitHub.',
                'messages' => [],
                'write_enabled' => $chat->writeEnabled(),
            ], 502);
        }
    }

    public function store(Request $request, AgentChatGithubService $chat): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'to' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:160'],
            'type' => ['nullable', 'string', 'in:INFO,QUESTION,REQUEST,REVIEW,DONE,BLOCKED,START'],
        ]);

        try {
            return response()->json(
                $chat->append($validated, $request->user()),
                201
            );
        } catch (RuntimeException $exception) {
            $status = $chat->writeEnabled() ? 409 : 503;

            return response()->json([
                'message' => $exception->getMessage(),
                'write_enabled' => $chat->writeEnabled(),
            ], $status);
        } catch (Throwable $exception) {
            Log::error('Agent Chat write failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível salvar a mensagem no GitHub.',
                'write_enabled' => $chat->writeEnabled(),
            ], 502);
        }
    }
}
