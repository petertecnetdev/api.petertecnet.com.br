<?php

namespace App\Services;

use App\Models\NotificationDelivery;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    public function verify(string $mode, string $token, string $challenge): ?string
    {
        $expected = trim((string) config('services.whatsapp.webhook_verify_token'));

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return null;
        }

        return $challenge;
    }

    public function signatureIsValid(string $rawBody, ?string $signature): bool
    {
        $secret = trim((string) config('services.whatsapp.app_secret'));

        if ($secret === '') {
            Log::error('WhatsApp webhook rejected because META_APP_SECRET is not configured.');
            return false;
        }

        if (! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public function process(array $payload): void
    {
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->processStatus((array) $status);
                }

                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $this->recordInboundMessage((array) $message, $value);
                }
            }
        }
    }

    private function processStatus(array $status): void
    {
        $messageId = trim((string) ($status['id'] ?? ''));
        $state = strtolower(trim((string) ($status['status'] ?? '')));

        if ($messageId === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $eventKey = hash('sha256', implode('|', [
            $messageId,
            $state,
            (string) ($status['timestamp'] ?? ''),
            (string) data_get($status, 'errors.0.code', ''),
        ]));

        $inserted = DB::table('notification_webhook_events')->insertOrIgnore([
            'provider' => 'meta_cloud_api',
            'event_key' => $eventKey,
            'provider_message_id' => $messageId,
            'event_type' => $state,
            'payload_hash' => hash('sha256', json_encode($status, JSON_UNESCAPED_SLASHES) ?: ''),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $delivery = NotificationDelivery::query()->where('provider_message_id', $messageId)->first();

        if ($delivery) {
            $updates = ['status' => strtoupper($state)];
            if ($state === 'sent') $updates['sent_at'] = $delivery->sent_at ?: now();
            if ($state === 'delivered') $updates['delivered_at'] = $delivery->delivered_at ?: now();
            if ($state === 'read') {
                $updates['delivered_at'] = $delivery->delivered_at ?: now();
                $updates['read_at'] = $delivery->read_at ?: now();
            }
            if ($state === 'failed') {
                $updates['failed_at'] = now();
                $updates['error_code'] = (string) data_get($status, 'errors.0.code', 'meta_failed');
                $updates['error_message'] = mb_substr((string) data_get($status, 'errors.0.title', 'Meta reported delivery failure.'), 0, 1000);
            }
            $delivery->forceFill($updates)->save();
        }

        // Backward compatibility for invitation/onboarding messages sent by the legacy service.
        $invitation = UserInvitation::query()->where('provider_message_id', $messageId)->first();
        if (! $invitation) {
            return;
        }

        $metadata = (array) ($invitation->metadata ?: []);
        $metadata['whatsapp_delivery'] = array_filter([
            'status' => $state,
            'timestamp' => $status['timestamp'] ?? null,
            'conversation_id' => data_get($status, 'conversation.id'),
            'pricing_category' => data_get($status, 'pricing.category'),
            'error_code' => data_get($status, 'errors.0.code'),
            'error_title' => data_get($status, 'errors.0.title'),
            'updated_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $invitation->forceFill(['delivery_status' => $state, 'metadata' => $metadata])->save();
    }

    private function recordInboundMessage(array $message, array $value): void
    {
        $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?? '';
        Log::info('WhatsApp inbound webhook received.', [
            'message_id' => $message['id'] ?? null,
            'from_masked' => $from === '' ? null : '***'.substr($from, -4),
            'type' => $message['type'] ?? null,
            'phone_number_id' => data_get($value, 'metadata.phone_number_id'),
        ]);
    }
}
