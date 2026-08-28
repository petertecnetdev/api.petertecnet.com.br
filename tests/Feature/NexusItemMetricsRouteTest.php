<?php

namespace Tests\Feature;

use Tests\TestCase;

class NexusItemMetricsRouteTest extends TestCase
{
    public function test_item_metrics_requires_authentication(): void
    {
        $response = $this->getJson('/api/account/item-metrics?app_id=2&establishment_id=1');

        $response->assertUnauthorized();
    }
}
