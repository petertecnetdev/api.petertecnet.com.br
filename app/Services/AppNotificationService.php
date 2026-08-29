<?php

namespace App\Services;

use App\Models\AppNotification;
use Illuminate\Support\Collection;

class AppNotificationService
{
    public function sendToUser(int $appId, int $userId, array $payload): AppNotification
    {
        return AppNotification::create([
            'app_id' => $appId,
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'general',
            'title' => $payload['title'] ?? 'Nova notificação',
            'message' => $payload['message'] ?? null,
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
            'reference_url' => $payload['reference_url'] ?? null,
            'data' => $payload['data'] ?? null,
        ]);
    }

    public function sendToUsers(int $appId, iterable $userIds, array $payload, ?int $excludeUserId = null): Collection
    {
        return collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && (!$excludeUserId || $id !== $excludeUserId))
            ->unique()
            ->values()
            ->map(fn ($userId) => $this->sendToUser($appId, $userId, $payload));
    }
}
