<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Str;

class WhatsAppNotificationService
{
    public function queue(AppNotification $notification, array $payload): ?NotificationDelivery
    {
        if (($payload['send_whatsapp'] ?? false) !== true || ! (bool) config('whatsapp.enabled')) {
            return null;
        }

        $user = User::query()->find((int) $notification->user_id);
        if (! $user) {
            return null;
        }

        $preferences = (array) data_get($user->extra_info, 'communication_preferences', []);
        if (array_key_exists('whatsapp_enabled', $preferences) && ! (bool) $preferences['whatsapp_enabled']) {
            return null;
        }

        $category = strtoupper((string) ($payload['category'] ?? 'UTILITY'));
        if ($category === 'MARKETING' && ! (bool) ($preferences['marketing_whatsapp_enabled'] ?? false)) {
            return null;
        }

        $phone = app(WhatsAppCloudProvider::class)->normalizeE164($user->phone_normalized ?: $user->phone);
        if (! $phone) {
            return null;
        }

        $template = app(WhatsAppTemplateRegistry::class)->resolve(
            (string) ($payload['whatsapp_template_key'] ?? strtoupper((string) $notification->type)),
            $payload['whatsapp_locale'] ?? null
        );
        $components = (array) ($payload['whatsapp_components'] ?? []);
        $idempotencyKey = hash('sha256', implode('|', [
            (string) $notification->id,
            $phone,
            $template['key'],
            json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'notification_id' => $notification->id,
                'app_id' => data_get($notification, 'app_id'),
                'user_id' => $notification->user_id,
                'establishment_id' => data_get($payload, 'establishment_id'),
                'channel' => 'whatsapp',
                'destination' => $this->maskPhone($phone),
                'destination_e164' => $phone,
                'provider' => 'meta_whatsapp_cloud',
                'template_key' => $template['key'],
                'template_name' => $template['name'],
                'locale' => $template['locale'],
                'status' => 'QUEUED',
                'correlation_id' => (string) ($payload['correlation_id'] ?? Str::uuid()),
                'queued_at' => now(),
            ]
        );

        if ($delivery->wasRecentlyCreated) {
            SendWhatsAppNotificationJob::dispatch($delivery->id, $components);
        }

        return $delivery;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        $visiblePrefix = min(3, max(1, strlen($digits) - 4));

        return '+'.substr($digits, 0, $visiblePrefix)
            .str_repeat('*', max(0, strlen($digits) - $visiblePrefix - 4))
            .substr($digits, -4);
    }
}
