<?php

namespace App\Services;

use App\Models\UserInvitation;
use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\WhatsAppCloudProvider;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppOnboardingService
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phones,
        private readonly WhatsAppCloudProvider $provider,
    ) {}

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
        try {
            return $this->phones->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
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

        $welcomeId = $this->provider->sendTemplate($phone, (string) config('services.whatsapp.invitation_template'), (string) config('services.whatsapp.language', 'pt_BR'), [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'text', 'text' => $applicationName],
                ['type' => 'text', 'text' => $activationUrl],
            ],
        ]]);

        $authenticationId = $this->provider->sendTemplate($phone, (string) config('services.whatsapp.authentication_template'), (string) config('services.whatsapp.language', 'pt_BR'), [
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
            'welcome_message_id' => $welcomeId,
            'authentication_message_id' => $authenticationId,
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

        return $this->provider->sendTemplate($phone, $template, (string) config('services.whatsapp.language', 'pt_BR'), [[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $name],
                ['type' => 'text', 'text' => $applicationName],
                ['type' => 'text', 'text' => $applicationUrl],
            ],
        ]]);
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
