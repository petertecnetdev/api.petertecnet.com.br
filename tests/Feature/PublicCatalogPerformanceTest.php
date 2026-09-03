<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_stays_within_query_payload_and_latency_budgets(): void
    {
        config([
            'public_catalog.cache_ttl_seconds' => 0,
            'observability.request_logging' => false,
        ]);

        $app = $this->applicationFixture('performance-contract', ['is_active' => true]);
        $now = now();

        foreach (range(1, 12) as $number) {
            $establishmentId = DB::table('establishments')->insertGetId([
                'app_id' => $app->id,
                'name' => "Empresa {$number}",
                'slug' => "performance-{$number}",
                'city' => 'Belo Horizonte',
                'uf' => 'MG',
                'is_featured' => $number <= 2,
                'is_published' => true,
                'is_approved' => true,
                'is_cancelled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('items')->insert([
                'app_id' => $app->id,
                'entity_id' => $establishmentId,
                'entity_name' => 'establishment',
                'name' => "Item {$number}",
                'slug' => "performance-item-{$number}",
                'price' => 15,
                'stock' => 10,
                'status' => true,
                'limited_by_user' => false,
                'is_featured' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $started = hrtime(true);
        $response = $this->getJson('/api/v1/apps/performance-contract/discovery?per_page=12');
        $durationMs = (hrtime(true) - $started) / 1_000_000;
        $payloadBytes = strlen($response->getContent());

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertLessThanOrEqual(
            config('public_catalog.performance.max_queries'),
            $queryCount,
            "Discovery executed {$queryCount} queries."
        );
        $this->assertLessThanOrEqual(
            config('public_catalog.performance.max_payload_bytes'),
            $payloadBytes,
            "Discovery payload has {$payloadBytes} bytes."
        );
        $this->assertLessThanOrEqual(
            config('public_catalog.performance.max_response_ms'),
            $durationMs,
            'Discovery exceeded the response-time budget: '.round($durationMs, 2).' ms.'
        );
    }
}
