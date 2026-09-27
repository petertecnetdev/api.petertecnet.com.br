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

    public static function normalize(array $event): array
    {
        $type = strtolower(trim((string) ($event['type'] ?? '')));
        $event['type'] = self::TYPES[$type] ?? $type;
        $event['route'] = $event['route'] ?? $event['page'] ?? null;
        $event['screen'] = $event['screen'] ?? $event['page'] ?? null;
        $event['result'] = $event['result'] ?? data_get($event, 'metadata.outcome');
        $event['duration_ms'] = isset($event['duration_ms']) ? (int) $event['duration_ms'] : null;
        $event['device'] = $event['device'] ?? null;

        return $event;
    }
}
