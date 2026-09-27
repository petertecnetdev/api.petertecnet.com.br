<?php

namespace Tests\Unit;

use App\Services\TelemetryEventSchema;
use PHPUnit\Framework\TestCase;

class TelemetryEventSchemaTest extends TestCase
{
    public function test_aliases_are_normalized_to_shared_event_names(): void
    {
        $event = TelemetryEventSchema::normalize([
            'type' => 'screen_view',
            'page' => '/checkout',
            'metadata' => ['outcome' => 'success'],
        ]);

        $this->assertSame('page_view', $event['type']);
        $this->assertSame('/checkout', $event['route']);
        $this->assertSame('/checkout', $event['screen']);
        $this->assertSame('success', $event['result']);
    }

    public function test_explicit_shared_fields_are_preserved(): void
    {
        $event = TelemetryEventSchema::normalize([
            'type' => 'payment_failed',
            'route' => '/pay',
            'screen' => 'payment',
            'duration_ms' => '240',
            'device' => 'mobile',
            'result' => 'failed',
        ]);

        $this->assertSame('payment', $event['type']);
        $this->assertSame('/pay', $event['route']);
        $this->assertSame('payment', $event['screen']);
        $this->assertSame(240, $event['duration_ms']);
        $this->assertSame('mobile', $event['device']);
        $this->assertSame('failed', $event['result']);
    }
}
