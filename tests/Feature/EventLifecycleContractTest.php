<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EventLifecycleContractTest extends TestCase
{
    public function test_generic_event_lifecycle_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasTable('event_lifecycle_actions'));
        $this->assertTrue(Schema::hasTable('commerce_refunds'));

        foreach ([
            'lifecycle_status',
            'sales_paused_at',
            'cancelled_at',
            'postponed_at',
            'rescheduled_at',
            'previous_start_date',
            'previous_end_date',
            'refund_deadline_at',
            'lifecycle_reason',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('events', $column),
                "Missing generic event lifecycle column: {$column}"
            );
        }

        foreach ([
            'app_id',
            'order_id',
            'payment_id',
            'status',
            'amount',
            'provider',
            'idempotency_key',
            'requested_at',
            'processed_at',
            'failed_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('commerce_refunds', $column),
                "Missing generic commerce refund column: {$column}"
            );
        }
    }

    public function test_generic_lifecycle_and_refund_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->values();

        foreach ([
            'api/v1/apps/{application}/events/{id}/lifecycle',
            'api/v1/apps/{application}/events/{id}/cancel',
            'api/v1/apps/{application}/events/{id}/postpone',
            'api/v1/apps/{application}/events/{id}/reschedule',
            'api/v1/apps/{application}/commerce/orders/{publicId}/refund',
            'api/v1/apps/{application}/commerce/refunds/{refundId}/retry',
        ] as $uri) {
            $this->assertTrue($uris->contains($uri), "Missing generic lifecycle route: {$uri}");
        }
    }
}
