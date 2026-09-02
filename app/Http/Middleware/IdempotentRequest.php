<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotentRequest
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $project = $request->attributes->get('api_project');
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            // Existing first-party JWT consumers remain backwards compatible.
            // Developer-project writes must always be replay-safe.
            return $project
                ? ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'Envie o header Idempotency-Key para operações de escrita.', 400, [], $request)
                : $next($request);
        }

        if (strlen($key) > 190) {
            return ApiResponse::error('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key excede o tamanho permitido.', 422, [], $request);
        }

        $applicationId = $this->applicationContext->has() ? $this->applicationContext->id() : null;
        $userId = $request->user()?->id;
        $contextKey = implode('|', [
            'project:' . ($project?->id ?? '-'),
            'app:' . ($applicationId ?? '-'),
            'user:' . ($userId ?? '-'),
        ]);
        $fingerprint = hash('sha256', implode('|', [
            $request->method(), $request->path(), hash('sha256', $request->getContent()), $contextKey,
        ]));

        $find = fn () => IdempotencyKey::query()->where('context_key', $contextKey)->where('key', $key)->first();
        if ($existing = $find()) return $this->replayOrConflict($request, $existing, $fingerprint);

        try {
            $record = IdempotencyKey::create([
                'api_project_id' => $project?->id,
                'application_id' => $applicationId,
                'user_id' => $userId,
                'context_key' => $contextKey,
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'locked_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
        } catch (QueryException $e) {
            if ($record = $find()) return $this->replayOrConflict($request, $record, $fingerprint);
            throw $e;
        }

        $response = $next($request);
        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'locked_at' => null,
        ]);

        return $response->header('Idempotency-Key', $key);
    }

    private function replayOrConflict(Request $request, IdempotencyKey $record, string $fingerprint): Response
    {
        if (! hash_equals($record->request_fingerprint, $fingerprint)) {
            return ApiResponse::error('IDEMPOTENCY_CONFLICT', 'A mesma chave foi reutilizada com uma requisição diferente.', 409, [], $request);
        }
        if ($record->response_status !== null && $record->response_body !== null) {
            return response($record->response_body, $record->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true')
                ->header('Idempotency-Key', $record->key);
        }
        return ApiResponse::error('IDEMPOTENCY_IN_PROGRESS', 'Uma requisição com esta chave ainda está sendo processada.', 409, [], $request);
    }
}
