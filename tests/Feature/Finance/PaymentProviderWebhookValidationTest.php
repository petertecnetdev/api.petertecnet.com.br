<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;

class PaymentProviderWebhookValidationTest extends TestCase
{
    public function test_payment_webhook_requires_provider_data_id(): void
    {
        $response = $this->postJson('/api/v1/payments/mercadopago/webhook', [
            'type' => 'payment',
            'data' => [],
        ]);

        $response->assertStatus(422);
    }
}
