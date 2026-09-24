<?php

namespace Tests\Unit;

use App\Services\TelemetryEventSchema;
use PHPUnit\Framework\TestCase;

class TelemetryEventCatalogTest extends TestCase
{
    /** @dataProvider sharedEventAliases */
    public function test_shared_aliases_map_to_one_canonical_event(string $alias, string $canonical): void
    {
        $event = TelemetryEventSchema::normalize([
            'type' => $alias,
            'route' => '/catalog',
            'screen' => 'catalog',
            'result' => 'ok',
            'duration_ms' => 12,
            'device' => 'web',
        ]);

        $this->assertSame($canonical, $event['type']);
        $this->assertSame('success', $event['result']);
        $this->assertSame(12, $event['duration_ms']);
    }

    public static function sharedEventAliases(): array
    {
        return [
            ['signin', 'login'],
            ['signout', 'logout'],
            ['screen_view', 'page_view'],
            ['created', 'create'],
            ['updated', 'update'],
            ['deleted', 'delete'],
            ['payment_success', 'payment'],
            ['payment_failed', 'payment'],
            ['reservation', 'booking'],
            ['frontend_error', 'error'],
        ];
    }

    public function test_context_fields_are_bounded_without_leaking_extra_payload(): void
    {
        $event = TelemetryEventSchema::normalize([
            'type' => 'conversion',
            'route' => "  /checkout\n",
            'screen' => ' confirmation ',
            'result' => 'succeeded',
            'duration_ms' => 250,
            'device' => ' mobile ',
            'metadata' => ['non_sensitive' => 'kept'],
            'password' => 'must-not-be-preserved',
            'token' => 'must-not-be-preserved',
            'unexpected_payload' => 'must-not-be-preserved',
        ]);

        $this->assertSame('/checkout', $event['route']);
        $this->assertSame('confirmation', $event['screen']);
        $this->assertSame('success', $event['result']);
        $this->assertSame(250, $event['duration_ms']);
        $this->assertSame('mobile', $event['device']);
        $this->assertSame(['non_sensitive' => 'kept'], $event['metadata']);
        $this->assertArrayNotHasKey('password', $event);
        $this->assertArrayNotHasKey('token', $event);
        $this->assertArrayNotHasKey('unexpected_payload', $event);
    }
}
