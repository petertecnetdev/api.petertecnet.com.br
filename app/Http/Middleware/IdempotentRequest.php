<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotentRequest
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', 'Envie o header Idempotency-Key para operações de escrita.', 400, [], $request);
        }

        if (strlen($key) > 190) {
            return ApiResponse::error('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key excede o tamanho permitido.', 422, [], $request);
        }

        $project = $request->attributes->get('api_project');
        $applicationId = $this->applicationContext->has() ? $this->applicationContext->id() : null;
        $userId = $request->user()?->id;
        $fingerprint = hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            hash('sha256', $request->getContent()),
            (string) $project?->id,
            (string) $applicationId,
            (string) $userId,
        ]));

        $query = IdempotencyKey::query()
            ->where('key', $key)
            ->when($project, fn ($q) => $q->where('api_project_id', $project->id), fn ($q) => $q->whereNull('api_project_id'))
            ->when($applicationId, fn ($q) => $q->where('application_id', $applicationId), fn ($q) => $q->whereNull('application_id'))
            ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'));

        $existing = $query->first();
        if ($existing) {
            if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                return ApiResponse::error('IDEMPOTENCY_CONFLICT', 'A mesma chave foi reutilizada com uma requisição diferente.', 409, [], $request);
            }

            if ($existing->response_status !== null && $existing->response_body !== null) {
                return response($existing->response_body, $existing->response_status)
                    ->header('Content-Type', 'application/json')
                    ->header('Idempotency-Replayed', 'true');
            }

            return ApiResponse::error('IDEMPOTENCY_IN_PROGRESS', 'Uma requisição com esta chave ainda está sendo processada.', 409, [], $request);
        }

        $record = DB::transaction(fn () => IdempotencyKey::create([
            'api_project_id' => $project?->id,
            'application_id' => $applicationId,
            'user_id' => $userId,
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'locked_at' => now(),
            'expires_at' => now()->addDay(),
        ]));

        $response = $next($request);
        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'locked_at' => null,
        ]);

        return $response->header('Idempotency-Key', $key);
    }
}
