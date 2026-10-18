<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use PHPUnit\Framework\TestCase;

final class IndependenceListingCommercePolicyTest extends TestCase
{
    public function test_curated_unclaimed_event_never_opens_commerce(): void
    {
        $event = $this->event('unclaimed', 'public_source_only', 'unclaimed', 'public_source_only');

        $this->assertFalse((new ListingCommercePolicy())->canSell($event));
        $this->assertFalse((new ListingCommercePolicy())->allowsLegacyOrVerified($event));
    }

    public function test_manual_claim_without_separate_commercial_approval_stays_closed(): void
    {
        $event = $this->event('verified', 'manual_approved', 'verified', 'manual_approved');
        $event->setAttribute('ownership_verified_at', '2026-10-17 12:00:00');
        $event->production->setAttribute('ownership_verified_at', '2026-10-17 12:00:00');

        // Mesmo com titularidade reconhecida, aprovação comercial continua ausente.
        $this->assertFalse((new ListingCommercePolicy())->canSell($event));
    }

    public function test_matching_legacy_organizer_records_keep_their_commerce_gate(): void
    {
        $event = $this->event('verified', 'legacy_owner_record', 'verified', 'legacy_owner_record');
        $event->setAttribute('listing_source', 'legacy_organizer');
        $event->production->setAttribute('listing_source', 'legacy_organizer');

        $this->assertTrue((new ListingCommercePolicy())->canSell($event));

        $event->setAttribute('created_by_user_id', 999);
        $this->assertFalse((new ListingCommercePolicy())->canSell($event));
    }

    public function test_mixed_application_records_never_sell(): void
    {
        $event = $this->event('verified', 'legacy_owner_record', 'verified', 'legacy_owner_record');
        $event->setAttribute('listing_source', 'legacy_organizer');
        $event->production->setAttribute('listing_source', 'legacy_organizer');
        $event->production->setAttribute('app_id', 22);

        $this->assertFalse((new ListingCommercePolicy())->canSell($event));
    }

    private function event(string $eventOwner, string $eventVerification, string $productionOwner, string $productionVerification): Event
    {
        $production = new Production();
        $production->forceFill([
            'id' => 17,
            'app_id' => 6,
            'user_id' => 42,
            'ownership_status' => $productionOwner,
            'verification_status' => $productionVerification,
            'category' => 'production',
        ]);

        $event = new Event();
        $event->forceFill([
            'id' => 71,
            'app_id' => 6,
            'production_id' => 17,
            'created_by_user_id' => 42,
            'ownership_status' => $eventOwner,
            'verification_status' => $eventVerification,
            'kind' => 'commercial',
        ]);
        $event->setRelation('production', $production);

        return $event;
    }
}
