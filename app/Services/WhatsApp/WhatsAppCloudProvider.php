<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WhatsAppCloudProvider
{
    public function __construct(private readonly PhoneNumberNormalizer $phones) {}

    public function sendTemplate(string $phone, string $template, string $language, array $components = []): string
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->phones->metaRecipient($phone),
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ], static fn ($value) => $value !== null),
        ];

        return $this->send($payload);
    }

    public function sendText(string $phone, string $text, bool $previewUrl = false): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'to' => $this->phones->metaRecipient($phone),
            'type' => 'text',
            'text' => ['preview_url' => $previewUrl, 'body' => $text],
        ]);
    }

    private function send(array $payload): string
    {
        if (! config('services.whatsapp.enabled')) {
            throw new WhatsAppProviderException('WhatsApp Cloud API is disabled.', 'disabled');
        }

        $version = trim((string) config('services.whatsapp.graph_version'));
        $phoneNumberId = trim((string) config('services.whatsapp.phone_number_id'));
        $token = trim((string) config('services.whatsapp.access_token'));

        if ($version === '' || $phoneNumberId === '' || $token === '') {
            throw new WhatsAppProviderException('WhatsApp Cloud API credentials are incomplete.', 'configuration');
        }

        try {
            // Intentionally no automatic retry here. A connection timeout after Meta accepts
            // the request is ambiguous and blindly retrying can duplicate a user message.
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.whatsapp.timeout', 15))
                ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", $payload);
        } catch (ConnectionException $e) {
            throw new WhatsAppProviderException('Ambiguous connection failure while calling Meta.', 'connection', false);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $code = (string) ($response->json('error.code') ?? $status);
            $message = trim((string) ($response->json('error.message') ?? 'Meta rejected the WhatsApp message.'));
            $message = mb_substr($message, 0, 500);

            throw new WhatsAppProviderException($message, $code, $status === 429 || $status >= 500);
        }

        $messageId = trim((string) $response->json('messages.0.id'));

        if ($messageId === '') {
            throw new WhatsAppProviderException('Meta response did not contain a message id.', 'missing_message_id');
        }

        return $messageId;
    }
}
