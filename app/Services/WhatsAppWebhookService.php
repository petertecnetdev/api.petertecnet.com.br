<?php

namespace App\Services;

use App\Models\UserInvitation;
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
            Log::warning('WhatsApp webhook app secret is not configured; signature validation is temporarily unavailable.');

            return true;
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

        $invitation = UserInvitation::query()
            ->where('provider_message_id', $messageId)
            ->first();

        if (! $invitation) {
            return;
        }

        $metadata = (array) ($invitation->metadata ?: []);
        $metadata['whatsapp_delivery'] = array_filter([
            'status' => $state,
            'timestamp' => $status['timestamp'] ?? null,
            'recipient_id' => $status['recipient_id'] ?? null,
            'conversation_id' => data_get($status, 'conversation.id'),
            'pricing_category' => data_get($status, 'pricing.category'),
            'error_code' => data_get($status, 'errors.0.code'),
            'error_title' => data_get($status, 'errors.0.title'),
            'updated_at' => now()->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $invitation->forceFill([
            'delivery_status' => $state,
            'metadata' => $metadata,
        ])->save();
    }

    private function recordInboundMessage(array $message, array $value): void
    {
        Log::info('WhatsApp inbound webhook received.', [
            'message_id' => $message['id'] ?? null,
            'from' => $message['from'] ?? null,
            'type' => $message['type'] ?? null,
            'phone_number_id' => data_get($value, 'metadata.phone_number_id'),
        ]);
    }
}
