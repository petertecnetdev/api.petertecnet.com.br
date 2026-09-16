<?php

namespace Tests\Unit\Support;

use App\Http\Controllers\SupportRequestController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SupportRequestIntakePolicyTest extends TestCase
{
    public function test_payment_and_payout_cannot_be_downgraded_below_high_priority(): void
    {
        $controller = new SupportRequestController();
        $method = new ReflectionMethod($controller, 'priorityFor');
        $method->setAccessible(true);

        $this->assertSame('high', $method->invoke($controller, 'payment', 'low'));
        $this->assertSame('high', $method->invoke($controller, 'payout', 'normal'));
        $this->assertSame('critical', $method->invoke($controller, 'payment', 'critical'));
        $this->assertSame('normal', $method->invoke($controller, 'bug', null));
    }

    public function test_reports_with_financial_context_are_prioritized_even_when_category_is_generic(): void
    {
        $controller = new SupportRequestController();
        $method = new ReflectionMethod($controller, 'priorityFor');
        $method->setAccessible(true);

        $this->assertSame('high', $method->invoke($controller, 'bug', 'low', ['payment_id' => 'pay_123']));
        $this->assertSame('high', $method->invoke($controller, 'usability', 'normal', ['checkout_id' => 'checkout_123']));
        $this->assertSame('high', $method->invoke($controller, 'general', null, ['order_id' => 123]));
        $this->assertSame('critical', $method->invoke($controller, 'bug', 'critical', ['payment_id' => 'pay_123']));
        $this->assertSame('low', $method->invoke($controller, 'bug', 'low', ['screen' => 'profile']));
    }

    public function test_support_metadata_policy_only_declares_non_sensitive_operational_context(): void
    {
        $reflection = new ReflectionClass(SupportRequestController::class);
        $keys = $reflection->getConstant('SAFE_METADATA_KEYS');

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
