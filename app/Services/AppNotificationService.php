<?php

namespace App\Services;

use App\Events\AppNotificationCreated;
use App\Mail\AppNotificationMail;
use App\Models\AppNotification;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AppNotificationService
{
    public function sendToUser(int $appId, int $userId, array $payload): AppNotification
    {
        $notification = AppNotification::create([
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

        event(new AppNotificationCreated($notification));

        if (($payload['send_email'] ?? true) !== false) {
            $this->sendNotificationEmail($notification);
        }

        return $notification;
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

    private function sendNotificationEmail(AppNotification $notification): void
    {
        try {
            $application = Application::query()->find((int) $notification->app_id);

            if (! $application || ! $this->shouldEmailApplication($application)) {
                return;
            }

            $recipient = User::query()->find((int) $notification->user_id);

            if (! $recipient || ! trim((string) $recipient->email)) {
                Log::warning('Notificação sem destinatário de e-mail válido.', [
                    'notification_id' => $notification->id,
                    'app_id' => $notification->app_id,
                    'user_id' => $notification->user_id,
                ]);

                return;
            }

            $actionUrl = $this->resolveActionUrl($notification, $application);

            Mail::to($recipient->email)->send(new AppNotificationMail(
                $recipient,
                $notification,
                $application,
                $actionUrl
            ));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar e-mail da notificação da aplicação.', [
                'notification_id' => $notification->id,
                'app_id' => $notification->app_id,
                'user_id' => $notification->user_id,
                'type' => $notification->type,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldEmailApplication(Application $application): bool
    {
        $identity = Str::lower(implode(' ', array_filter([
            $application->name,
            $application->slug,
            $application->url,
        ])));

        return Str::contains($identity, ['cutinapp', 'cutin', 'chatnap', 'catchnap']);
    }

    private function resolveActionUrl(AppNotification $notification, Application $application): string
    {
        $baseUrl = rtrim(trim((string) $application->url), '/');

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $baseUrl = 'https://cutinapp.petertecnet.com.br';
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $candidates = [
            $notification->reference_url,
            $data['target_url'] ?? null,
            $data['action_url'] ?? null,
            $data['event_url'] ?? null,
            $data['reference_url'] ?? null,
            $data['url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }

            if (Str::startsWith($candidate, '/')) {
                return $baseUrl.$candidate;
            }

            return $baseUrl.'/'.ltrim($candidate, '/');
        }

        return $baseUrl;
    }
}
