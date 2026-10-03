<?php

namespace Tests\Unit\Support;

use App\Services\Support\SupportRequestIntakeService;
use PHPUnit\Framework\TestCase;

class SupportRequestIntakePolicyTest extends TestCase
{
    public function test_payment_and_payout_cannot_be_downgraded_below_high_priority(): void
    {
        $service = new SupportRequestIntakeService();
        $this->assertSame('high', $service->priorityFor('payment', 'low'));
        $this->assertSame('high', $service->priorityFor('payout', 'normal'));
        $this->assertSame('critical', $service->priorityFor('payment', 'critical'));
        $this->assertSame('normal', $service->priorityFor('bug', null));
    }

    public function test_reports_with_financial_context_are_prioritized_even_when_category_is_generic(): void
    {
        $service = new SupportRequestIntakeService();
        $this->assertSame('high', $service->priorityFor('bug', 'low', ['payment_id' => 'pay_123']));
        $this->assertSame('high', $service->priorityFor('usability', 'normal', ['checkout_id' => 'checkout_123']));
        $this->assertSame('high', $service->priorityFor('general', null, ['order_id' => 123]));
        $this->assertSame('critical', $service->priorityFor('bug', 'critical', ['payment_id' => 'pay_123']));
        $this->assertSame('low', $service->priorityFor('bug', 'low', ['screen' => 'profile']));
    }

    public function test_support_metadata_policy_only_declares_non_sensitive_operational_context(): void
    {
        $keys = SupportRequestIntakeService::SAFE_METADATA_KEYS;
        $this->assertContains('order_id', $keys);
        $this->assertContains('payment_id', $keys);
        $this->assertContains('screen', $keys);
        $this->assertNotContains('password', $keys);
        $this->assertNotContains('access_token', $keys);
        $this->assertNotContains('authorization', $keys);
        $this->assertNotContains('card_number', $keys);
        $this->assertNotContains('pix_key', $keys);
    }
}
