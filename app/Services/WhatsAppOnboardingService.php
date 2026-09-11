<?php

namespace App\Services;

use App\Models\UserInvitation;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppOnboardingService
{
    public function isConfigured(): bool
    {
        return (bool) config('services.whatsapp.enabled')
            && trim((string) config('services.whatsapp.phone_number_id')) !== ''
            && trim((string) config('services.whatsapp.access_token')) !== ''
            && trim((string) config('services.whatsapp.invitation_template')) !== ''
            && trim((string) config('services.whatsapp.authentication_template')) !== '';
    }

    public function normalizePhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') return null;

        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);

        if (str_starts_with($raw, '+')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) $digits = '55'.$digits;
        if (strlen($digits) < 12 || strlen($digits) > 15) return null;

        return '+'.$digits;
    }

    public function sendInvitation(UserInvitation $invitation, string $code, string $rawToken): array
    {
        $this->assertConfigured();
        $invitation->loadMissing(['user', 'application']);

        $phone = $this->normalizePhone($invitation->destination ?: $invitation->user?->phone_normalized ?: $invitation->user?->phone);
        if (! $phone) throw new RuntimeException('O número de WhatsApp do convite é inválido.');

        $name = trim((string) data_get($invitation->metadata, 'recipient_name'))
            ?: trim((string) $invitation->user?->first_name)
            ?: 'cliente';
        $applicationName = (string) ($invitation->application?->name ?: 'Peter Tecnet');
        $activationUrl = $this->activationUrl($rawToken);

        $welcome = $this->sendTemplate($phone, (string) config('services.whatsapp.invitation_template'), [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'text', 'text' => $applicationName],
                ['type' => 'text', 'text' => $activationUrl],
            ],
        ]]);

        $authentication = $this->sendTemplate($phone, (string) config('services.whatsapp.authentication_template'), [
            [
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
            [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $code]],
            ],
        ]);

        return [
            'welcome_message_id' => data_get($welcome, 'messages.0.id'),
            'authentication_message_id' => data_get($authentication, 'messages.0.id'),
        ];
    }

    public function sendActivationComplete(UserInvitation $invitation): ?string
    {
        if (! $this->isConfigured()) return null;

        $template = trim((string) config('services.whatsapp.activation_template'));
        if ($template === '') return null;

        $invitation->loadMissing(['user', 'application']);
        $phone = $this->normalizePhone($invitation->destination ?: $invitation->user?->phone_normalized ?: $invitation->user?->phone);
        if (! $phone) return null;

        $name = trim((string) $invitation->user?->first_name) ?: 'cliente';
        $applicationName = (string) ($invitation->application?->name ?: 'Peter Tecnet');
        $applicationUrl = (string) ($invitation->application?->url ?: config('app.frontend_url', 'https://petertecnet.com.br'));

        $response = $this->sendTemplate($phone, $template, [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'text', 'text' => $applicationName],
                ['type' => 'text', 'text' => $applicationUrl],
            ],
        ]]);

        return data_get($response, 'messages.0.id');
    }

    private function sendTemplate(string $phone, string $templateName, array $components): array
    {
        $phoneNumberId = trim((string) config('services.whatsapp.phone_number_id'));
        $version = trim((string) config('services.whatsapp.graph_version', 'v26.0')) ?: 'v26.0';

        $response = $this->client()->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $phone),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => (string) config('services.whatsapp.language', 'pt_BR')],
                'components' => $components,
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', 'Falha ao enviar mensagem pela WhatsApp Cloud API.'));
        }

        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.whatsapp.access_token'))
            ->acceptJson()
            ->asJson()
            ->timeout(max(5, (int) config('services.whatsapp.timeout', 15)))
            ->retry(2, 250, throw: false);
    }

    private function activationUrl(string $rawToken): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://petertecnet.com.br'), '/');
        return $base.'/account/activate?'.http_build_query(['token' => $rawToken]);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Cloud API ainda não está configurada para a Peter Tecnet.');
        }
    }
}
