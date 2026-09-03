<?php

namespace App\Services;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RequestDeduplicationService
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, Application $application): Response
    {
        if (! in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $token = trim((string) $request->header(self::HEADER, ''));
        if ($token === '') {
            return $next($request);
        }

        abort_if(strlen($token) > 128, 422, 'A chave de idempotência deve ter no máximo 128 caracteres.');

        $scope = (string) (optional($request->route())->uri() ?: $request->path());
        $principal = trim((string) $request->header('Authorization', '')) ?: 'anonymous';
        $principalHash = hash('sha256', $principal);
        $scopeHash = hash('sha256', $request->method().'|'.$scope);
        $tokenHash = hash('sha256', $token);
        $requestHash = hash('sha256', $this->canonicalRequest($request));

        DB::table('request_deduplication_records')
            ->where('expires_at', '<=', now())
            ->delete();

        $identity = [
            'app_id' => (int) $application->id,
            'principal_hash' => $principalHash,
            'scope_hash' => $scopeHash,
            'token_hash' => $tokenHash,
        ];

        $inserted = DB::table('request_deduplication_records')->insertOrIgnore($identity + [
            'request_hash' => $requestHash,
            'scope' => mb_substr($scope, 0, 255),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = DB::table('request_deduplication_records')->where($identity)->first();

        if (! $record) {
            return $next($request);
        }

        if ((string) $record->request_hash !== $requestHash) {
            return response()->json([
                'success' => false,
                'message' => 'A mesma chave de idempotência foi usada com uma requisição diferente.',
                'code' => 'IDEMPOTENCY_CONFLICT',
                'request_id' => $request->attributes->get('request_id'),
            ], 409);
        }

        if ($inserted === 0) {
            if ($record->response_status !== null) {
                $headers = json_decode((string) ($record->response_headers ?? '{}'), true) ?: [];
                $headers['Idempotency-Replayed'] = 'true';

                return response((string) $record->response_body, (int) $record->response_status, $headers);
            }

            return response()->json([
                'success' => false,
                'message' => 'Uma requisição equivalente ainda está em processamento.',
                'code' => 'IDEMPOTENCY_IN_PROGRESS',
                'request_id' => $request->attributes->get('request_id'),
            ], 409, ['Retry-After' => '1']);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            DB::table('request_deduplication_records')->where($identity)->delete();
            throw $exception;
        }

        if ($response->getStatusCode() >= 500 || ! method_exists($response, 'getContent')) {
            DB::table('request_deduplication_records')->where($identity)->delete();
            return $response;
        }

        $contentType = $response->headers->get('Content-Type');
        $headers = $contentType ? ['Content-Type' => $contentType] : [];

        DB::table('request_deduplication_records')->where($identity)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'response_headers' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expires_at' => now()->addDay(),
            'updated_at' => now(),
        ]);

        $response->headers->set('Idempotency-Replayed', 'false');

        return $response;
    }

    private function canonicalRequest(Request $request): string
    {
        $payload = $this->normalize($request->all());

        return json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
