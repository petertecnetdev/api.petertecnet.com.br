<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use LogicException;

/**
 * Test-only setup for downstream pass/QR/check-in regressions.
 *
 * The normal self-service creation workflow MUST NOT authorize commerce.
 * These tests explicitly emulate pre-Independence organizer records whose
 * owner was known before the new verification requirement. Curated records,
 * foreign app records and mismatched organizers can never use this fixture.
 *
 * Independence intake/settlement security tests must instead exercise the
 * real unverified and manually approved workflows without this helper.
 */
trait UsesAuthorizedLegacyListingFixture
{
    protected function authorizeLegacyListingFixture(int $eventId, int $organizerId): void
    {
        $event = Event::query()->with('production')->findOrFail($eventId);
        $production = $event->production;

        if (! $production instanceof Production
            || (int) $event->app_id < 1
            || (int) $production->app_id !== (int) $event->app_id
            || (int) $production->user_id !== $organizerId
            || ((int) $event->created_by_user_id > 0
                && (int) $event->created_by_user_id !== $organizerId)
            || filled($event->source_url)
            || filled($production->source_url)
            || in_array($event->listing_source, ['curated', 'curatorial', 'public_curated', 'institutional'], true)
            || in_array($production->listing_source, ['curated', 'curatorial', 'public_curated', 'institutional'], true)) {
            throw new LogicException('Only a same-app, non-curatorial organizer test record can be marked as legacy.');
        }

        // Provenance is explicit; simply having null owner markers NEVER
        // authorizes a new or curated production in the production runtime.
        foreach ([$production, $event] as $entity) {
            $entity->forceFill([
                'ownership_status' => null,
                'listing_source' => 'legacy_organizer',
                'verification_status' => 'legacy_owner_record',
                'source_url' => null,
            ])->saveOrFail();
        }

        if (! app(ListingCommercePolicy::class)
            ->allowsLegacyOrVerified($event->fresh()->load('production'))) {
            throw new LogicException('Legacy fixture did not satisfy the actual commerce policy.');
        }
    }
}
