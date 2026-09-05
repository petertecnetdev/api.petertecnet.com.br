<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    private const ID_PATTERN = '/^[A-Za-z0-9._:-]{8,100}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->validId($request->headers->get('X-Request-ID')) ?? (string) Str::uuid();
        $correlationId = $this->validId($request->headers->get('X-Correlation-ID')) ?? $requestId;

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::withContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }

    private function validId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1 ? $value : null;
    }
}
