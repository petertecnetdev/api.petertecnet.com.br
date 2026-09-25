<?php

namespace Tests\Unit;

use App\Services\WhatsApp\PhoneNumberNormalizer;
use App\Services\WhatsApp\WhatsAppCloudProvider;
use App\Services\WhatsApp\WhatsAppTemplateRegistry;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppInfrastructureTest extends TestCase
{
    public function test_phone_normalization_is_global_and_never_assumes_brazil(): void
    {
        config()->set('services.whatsapp.default_calling_code', null);
        $service = app(PhoneNumberNormalizer::class);

        $this->assertSame('+14155552671', $service->normalize('+1 (415) 555-2671'));
        $this->assertSame('+442079460018', $service->normalize('0044 20 7946 0018'));

        $this->expectException(InvalidArgumentException::class);
        $service->normalize('62999999999');
    }

    public function test_local_number_can_use_an_explicit_configured_calling_code(): void
    {
        config()->set('services.whatsapp.default_calling_code', '55');
        $this->assertSame('+5562999999999', app(PhoneNumberNormalizer::class)->normalize('62 99999-9999'));
    }

    public function test_semantic_template_mapping_is_independent_from_meta_name(): void
    {
        config()->set('services.whatsapp.templates.PAYMENT_CONFIRMED', 'meta_payment_v7');
        config()->set('services.whatsapp.locales.en', 'en_US');

        $resolved = app(WhatsAppTemplateRegistry::class)->resolve('payment_confirmed', 'en');

        $this->assertSame('PAYMENT_CONFIRMED', $resolved['type']);
        $this->assertSame('meta_payment_v7', $resolved['name']);
        $this->assertSame('en_US', $resolved['language']);
    }

    public function test_cloud_provider_returns_meta_message_id_without_exposing_token_in_payload(): void
    {
        config()->set('services.whatsapp.enabled', true);
        config()->set('services.whatsapp.graph_version', 'v26.0');
        config()->set('services.whatsapp.phone_number_id', 'phone-123');
        config()->set('services.whatsapp.access_token', 'secret-test-token');
        config()->set('services.whatsapp.timeout', 15);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200)]);

        $id = app(WhatsAppCloudProvider::class)->sendTemplate('+14155552671', 'payment_confirmed', 'en_US');
        $this->assertSame('wamid.123', $id);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/v26.0/phone-123/messages')
                && $request->hasHeader('Authorization', 'Bearer secret-test-token')
                && ! array_key_exists('access_token', $request->data())
                && ($request->data()['to'] ?? null) === '14155552671';
        });
    }

    public function test_webhook_requires_app_secret_and_valid_hmac_signature(): void
    {
        $service = app(WhatsAppWebhookService::class);
        config()->set('services.whatsapp.app_secret', null);
        $this->assertFalse($service->signatureIsValid('{"ok":true}', null));

        config()->set('services.whatsapp.app_secret', 'app-secret');
        $raw = '{"ok":true}';
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'app-secret');

        $this->assertTrue($service->signatureIsValid($raw, $signature));
        $this->assertFalse($service->signatureIsValid($raw, 'sha256=invalid'));
    }
}
