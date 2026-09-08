<?php

namespace App\Services;

use App\Events\AppNotificationCreated;
use App\Models\AppNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class AppNotificationService
{
    public function sendToUser(int $appId, int $userId, array $payload): AppNotification
    {
        return $this->createAndBroadcast($appId, $userId, $payload);
    }

    public function sendToUserOnce(int $appId, int $userId, string $dedupeKey, array $payload): AppNotification
    {
        $key = trim($dedupeKey);
        if ($key === '') {
            throw new \InvalidArgumentException('A notification dedupe key is required.');
        }

        $identity = [
            'app_id' => $appId,
            'user_id' => $userId,
            'dedupe_key' => $key,
        ];

        try {
            $notification = AppNotification::query()->firstOrCreate($identity, $this->attributes($payload));
        } catch (QueryException $e) {
            $notification = AppNotification::query()->where($identity)->first();
            if (! $notification) {
                throw $e;
            }
        }

        if ($notification->wasRecentlyCreated) {
            event(new AppNotificationCreated($notification));
        }

        return $notification;
    }

    public function sendToUsers(int $appId, iterable $userIds, array $payload, ?int $excludeUserId = null): Collection
    {
        return collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && (! $excludeUserId || $id !== $excludeUserId))
            ->unique()
            ->values()
            ->map(fn ($userId) => $this->sendToUser($appId, $userId, $payload));
    }

    private function createAndBroadcast(int $appId, int $userId, array $payload): AppNotification
    {
        $notification = AppNotification::create(array_merge([
            'app_id' => $appId,
            'user_id' => $userId,
        ], $this->attributes($payload)));

        event(new AppNotificationCreated($notification));

        return $notification;
    }

    private function attributes(array $payload): array
    {
        return [
            'type' => $payload['type'] ?? 'general',
            'title' => $payload['title'] ?? 'Nova notificação',
            'message' => $payload['message'] ?? null,
            'reference_type' => $payload['reference_type'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
            'reference_url' => $payload['reference_url'] ?? null,
            'data' => $payload['data'] ?? null,
        ];
    }
}
