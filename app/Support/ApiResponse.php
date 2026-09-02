<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'error' => null,
        ], $status);
    }

    public static function paginated(LengthAwarePaginator $paginator, mixed $data = null, array $meta = []): JsonResponse
    {
        return self::success(
            $data ?? $paginator->items(),
            array_merge($meta, [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'last' => $paginator->url($paginator->lastPage()),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ])
        );
    }

    public static function error(string $code, string $message, int $status, array $details = [], ?Request $request = null): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) [],
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => $request?->attributes->get('request_id'),
            ],
        ], $status);
    }
}
