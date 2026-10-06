<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudProvider
{
    public function configured(): bool
    {
        return (bool) config('whatsapp.enabled')
            && trim((string) config('whatsapp.phone_number_id')) !== ''
            && trim((string) config('whatsapp.access_token')) !== '';
    }

    public function normalizeE164(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (! str_starts_with($phone, '+')) {
            return null;
        }

        $normalized = '+'.preg_replace('/\D+/', '', substr($phone, 1));

        return preg_match('/^\+[1-9]\d{7,14}$/', $normalized) ? $normalized : null;
    }

    public function sendTemplate(string $phone, string $templateName, string $locale, array $components = []): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Cloud API não configurada.');
        }

        $e164 = $this->normalizeE164($phone);
        if (! $e164) {
            throw new RuntimeException('Destino WhatsApp deve estar em E.164 internacional.');
        }

        $version = trim((string) config('whatsapp.graph_version', 'v26.0')) ?: 'v26.0';
        $phoneNumberId = trim((string) config('whatsapp.phone_number_id'));
        $response = $this->client()->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($e164, '+'),
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $locale],
                'components' => $components ?: null,
            ], static fn ($value) => $value !== null),
        ]);

        if (! $response->successful()) {
            $code = (string) data_get($response->json(), 'error.code', $response->status());
            throw new RuntimeException('Meta WhatsApp Cloud API recusou a mensagem (código '.$code.').');
        }

        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('whatsapp.timeout', 15)));
    }
}
