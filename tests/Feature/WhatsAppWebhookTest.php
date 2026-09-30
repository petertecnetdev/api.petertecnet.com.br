<?php

namespace Tests\Feature;

use App\Services\WhatsAppWebhookService;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    public function test_verifies_challenge_with_configured_token(): void
    {
        config(['services.whatsapp.webhook_verify_token' => 'verify-me']);
        $service = app(WhatsAppWebhookService::class);

        $this->assertSame('12345', $service->verify('subscribe', 'verify-me', '12345'));
        $this->assertNull($service->verify('subscribe', 'wrong', '12345'));
    }

    public function test_rejects_invalid_signature_when_app_secret_is_configured(): void
    {
        config(['services.whatsapp.app_secret' => 'secret']);
        $service = app(WhatsAppWebhookService::class);
        $body = '{"object":"whatsapp_business_account"}';

        $valid = 'sha256='.hash_hmac('sha256', $body, 'secret');
        $this->assertTrue($service->signatureIsValid($body, $valid));
        $this->assertFalse($service->signatureIsValid($body, 'sha256=invalid'));
    }
}
