<?php

namespace App\Services;

final class TelemetryEventSchema
{
    private const TYPES = [
        'login' => 'login', 'signin' => 'login',
        'logout' => 'logout', 'signout' => 'logout',
        'page_view' => 'page_view', 'screen_view' => 'page_view', 'view' => 'page_view',
        'create' => 'create', 'created' => 'create',
        'update' => 'update', 'updated' => 'update',
        'delete' => 'delete', 'deleted' => 'delete',
        'search' => 'search',
        'share' => 'share',
        'checkout' => 'checkout',
        'purchase' => 'purchase',
        'payment' => 'payment', 'payment_success' => 'payment', 'payment_failed' => 'payment',
        'booking' => 'booking', 'reservation' => 'booking',
        'ticket' => 'ticket',
        'message' => 'message',
        'notification' => 'notification',
        'error' => 'error', 'frontend_error' => 'error',
        'conversion' => 'conversion',
    ];

    private const RESULTS = [
        'ok' => 'success', 'succeeded' => 'success', 'success' => 'success',
        'error' => 'error', 'failed' => 'failed', 'failure' => 'failed',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'pending' => 'pending',
    ];

    public static function normalize(array $event): array
    {
        $type = strtolower(trim((string) ($event['type'] ?? '')));
        $event['type'] = self::TYPES[$type] ?? $type;
        $event['route'] = self::boundedString($event['route'] ?? $event['page'] ?? null, 1000);
        $event['screen'] = self::boundedString($event['screen'] ?? $event['page'] ?? null, 1000);
        $event['result'] = self::normalizeResult($event['result'] ?? data_get($event, 'metadata.outcome'));
        $event['duration_ms'] = self::normalizeDuration($event['duration_ms'] ?? null);
        $event['device'] = self::boundedString($event['device'] ?? null, 80);

        return $event;
    }

    private static function normalizeResult(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : (self::RESULTS[$normalized] ?? $normalized);
    }

    private static function normalizeDuration(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $duration = (int) $value;

        return $duration >= 0 && $duration <= 86400000 ? $duration : null;
    }

    private static function boundedString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
