<?php

declare(strict_types=1);

namespace Tests\Unit\Independence;

use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use PHPUnit\Framework\TestCase;

final class ListingCommerceCompatibilityTest extends TestCase
{
    private function pair(?string $eventOwnership, ?string $productionOwnership, int $appId = 1): Event
    {
        $event = new Event();
        $event->forceFill(['app_id' => 1, 'kind' => 'commercial', 'ownership_status' => $eventOwnership]);
        $production = new Production();
        $production->forceFill(['app_id' => $appId, 'ownership_status' => $productionOwnership]);
        $event->setRelation('production', $production);

        return $event;
    }

    public function test_legacy_producer_and_events_without_markers_preserve_checkout(): void
    {
        self::assertTrue((new ListingCommercePolicy())->allowsLegacyOrVerified($this->pair(null, null)));
    }

    public function test_unclaimed_event_or_production_never_enables_checkout(): void
    {
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair('unclaimed', null)));
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair(null, 'unclaimed')));
        self::assertFalse($policy->allowsLegacyOrVerified($this->pair('pending_claim', 'verified')));
    }

    public function test_legacy_event_with_curatorial_metadata_cannot_sell(): void
    {
        $event = $this->pair(null, null);
        $event->forceFill(['extra_info' => [
            'curation' => ['sale_mode' => 'information_only', 'ticket_checkout_enabled' => false],
        ]]);
        $policy = new ListingCommercePolicy();
        self::assertFalse($policy->allowsLegacyOrVerified($event));
        self::assertFalse($policy->canSell($event));
    }

    public function test_cross_application_record_never_allows_checkout(): void
    {
        self::assertFalse((new ListingCommercePolicy())->allowsLegacyOrVerified($this->pair(null, null, 2)));
    }
}
