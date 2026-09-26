<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class EnsureIdempotentRequest
{
    private const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header(self::HEADER, ''));
        if ($key === '') {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/', $key)) {
            return response()->json(['message' => 'A chave de idempotência é inválida.'], 422);
        }

        $actorKey = $this->actorKey($request);
        $applicationKey = $this->applicationKey($request);
        $routeSignature = strtoupper($request->method()).' '.$this->routeSignature($request);
        $fingerprint = hash('sha256', json_encode([
            'route' => $routeSignature,
            'route_parameters' => $this->normalize($this->routeParameters($request)),
            'query' => $this->normalize($request->query()),
            'payload' => $this->normalize($request->all()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $existing = $this->find($applicationKey, $actorKey, $key);
        if ($existing) {
            return $this->replay($existing, $fingerprint, $key);
        }

        try {
            DB::table('idempotent_requests')->insert([
                'application_key' => $applicationKey,
                'actor_key' => $actorKey,
                'idempotency_key' => $key,
                'route_signature' => $routeSignature,
                'request_fingerprint' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = $this->find($applicationKey, $actorKey, $key);
            if ($existing) {
                return $this->replay($existing, $fingerprint, $key);
            }
            throw $exception;
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($this->shouldReleaseAfterException($request, $exception)) {
                $this->release($applicationKey, $actorKey, $key);
            }
            throw $exception;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (! str_contains(strtolower($contentType), 'json')) {
            $this->release($applicationKey, $actorKey, $key);
            return $response;
        }

        DB::table('idempotent_requests')
            ->where('application_key', $applicationKey)
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $key)
            ->whereNull('completed_at')
            ->update([
                'response_status' => $response->getStatusCode(),
                'content_type' => $contentType,
                'response_body' => $response->getContent(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $response->headers->set('Idempotency-Status', 'created');
        return $response;
    }

    private function find(string $applicationKey, string $actorKey, string $key): ?object
    {
        return DB::table('idempotent_requests')
            ->where('application_key', $applicationKey)
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function replay(object $record, string $fingerprint, string $key): Response
    {
        if (! hash_equals((string) $record->request_fingerprint, $fingerprint)) {
            return response()->json(['message' => 'Esta chave de idempotência já foi usada com dados diferentes.'], 409, ['Idempotency-Status' => 'conflict']);
        }

        if ($record->completed_at === null) {
            return response()->json(['message' => 'Esta operação já está em processamento. Tente novamente em instantes.'], 425, [
                'Idempotency-Status' => 'processing',
                'Retry-After' => '2',
            ]);
        }

        $response = response((string) ($record->response_body ?? ''), (int) ($record->response_status ?? 200), [
            'Content-Type' => (string) ($record->content_type ?: 'application/json'),
        ]);
        $response->headers->set('Idempotency-Status', 'replayed');
        $response->headers->set('Idempotency-Replayed', 'true');
        return $response;
    }

    private function actorKey(Request $request): string
    {
        try {
            $user = Auth::guard('api')->user();
            if ($user && $user->getAuthIdentifier() !== null) {
                return 'user:'.(string) $user->getAuthIdentifier();
            }
        } catch (Throwable) {
            // Route authentication will still enforce access after this middleware.
        }

        $token = trim((string) $request->bearerToken());
        if ($token !== '') {
            return 'token:'.hash('sha256', $token);
        }

        return 'guest';
    }

    private function applicationKey(Request $request): string
    {
        $application = $request->attributes->get('application')
            ?: $request->attributes->get('application_slug')
            ?: $request->attributes->get('peter.application_slug')
            ?: $request->route('application')
            ?: $request->header('X-Peter-App')
            ?: $request->header('X-App-Slug');

        if (is_object($application)) {
            $application = $application->slug ?? $application->id ?? null;
        }

        $value = strtolower(trim((string) ($application ?: 'global')));
        return substr($value, 0, 80);
    }

    private function routeSignature(Request $request): string
    {
        $route = $request->route();
        return $route && method_exists($route, 'uri')
            ? (string) $route->uri()
            : '/'.ltrim($request->path(), '/');
    }

    private function routeParameters(Request $request): array
    {
        $route = $request->route();
        if (! $route || ! method_exists($route, 'parameters')) {
            return [];
        }

        return array_map(static function (mixed $value): mixed {
            if (is_object($value)) {
                if (method_exists($value, 'getRouteKey')) {
                    return $value->getRouteKey();
                }
                if (isset($value->id)) {
                    return $value->id;
                }
                return get_class($value);
            }
            return $value;
        }, $route->parameters());
    }

    private function shouldReleaseAfterException(Request $request, Throwable $exception): bool
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        if ($status < 500 || $status === 503) {
            return true;
        }

        $route = $request->route();
        $action = $route && method_exists($route, 'getActionName') ? (string) $route->getActionName() : '';
        return ! str_ends_with($action, 'OrderingController@checkout');
    }

    private function release(string $applicationKey, string $actorKey, string $key): void
    {
        DB::table('idempotent_requests')
            ->where('application_key', $applicationKey)
            ->where('actor_key', $actorKey)
            ->where('idempotency_key', $key)
            ->whereNull('completed_at')
            ->delete();
    }

    private function normalize(mixed $value, ?string $field = null): mixed
    {
        if ($field === 'card_token') {
            return '[volatile]';
        }

        if ($value instanceof UploadedFile) {
            $path = $value->getRealPath();
            $readable = $value->isValid()
                && is_string($path)
                && $path !== ''
                && is_file($path)
                && is_readable($path);
            $contentHash = $readable ? hash_file('sha256', $path) : false;

            return [
                '__uploaded_file' => true,
                'sha256' => $contentHash ?: null,
                'size' => $readable ? $value->getSize() : null,
                'mime_type' => $readable ? $value->getMimeType() : $value->getClientMimeType(),
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item, (string) $key);
        }
        return $value;
    }
}
