<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class WhatsAppChannel
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phones,
        private readonly WhatsAppTemplateRegistry $templates,
    ) {}

    public function queue(AppNotification $notification, array $options): ?NotificationDelivery
    {
        $user = User::query()->find((int) $notification->user_id);
        if (! $user) {
            throw new RuntimeException('Notification user not found.');
        }

        $classification = strtoupper((string) ($options['classification'] ?? 'UTILITY'));
        $allowed = ['AUTHENTICATION', 'TRANSACTIONAL', 'UTILITY', 'MARKETING'];
        if (! in_array($classification, $allowed, true)) {
            throw new RuntimeException('Invalid WhatsApp notification classification.');
        }

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($notification) {
                $query->where('app_id', $notification->app_id)->orWhereNull('app_id');
            })
            ->orderByRaw('app_id IS NULL')
            ->first();

        if ($preference && ! $preference->whatsapp_enabled) {
            return null;
        }

        if ($classification === 'MARKETING' && (! $preference || ! $preference->marketing_whatsapp_enabled)) {
            return null;
        }

        // Existing WhatsApp verification is treated as the minimum consent signal for
        // non-marketing messages when no explicit preference record exists.
        if (! $preference && ! $user->whatsapp_verified_at) {
            return null;
        }

        $phone = $this->phones->normalize((string) ($user->whatsapp ?: $user->phone));
        $resolved = $this->templates->resolve((string) ($options['template'] ?? $notification->type), $options['locale'] ?? $user->preferred_locale);
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? '')) ?: hash('sha256', implode('|', [
            $notification->id,
            $resolved['type'],
            $phone,
        ]));

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'notification_id' => $notification->id,
                'app_id' => $notification->app_id,
                'user_id' => $notification->user_id,
                'establishment_id' => $user->establishment_id,
                'channel' => 'whatsapp',
                'classification' => $classification,
                'destination_masked' => $this->phones->mask($phone),
                'provider' => 'meta_cloud_api',
                'template' => $resolved['name'],
                'template_locale' => $resolved['language'],
                'status' => 'QUEUED',
                'correlation_id' => (string) Str::uuid(),
                'queued_at' => now(),
            ]
        );

        if ($delivery->wasRecentlyCreated) {
            SendWhatsAppNotificationJob::dispatch(
                $delivery->id,
                $phone,
                $resolved['name'],
                $resolved['language'],
                (array) ($options['components'] ?? []),
            );
        }

        return $delivery;
    }
}
