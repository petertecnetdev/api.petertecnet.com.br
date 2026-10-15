<?php

namespace App\Services\Independence;

use App\Models\Event;
use App\Models\Production;

/**
 * This is a prerequisite for new sales, not a replacement for existing
 * merchant onboarding, payment and ticket inventory authorizations.
 */
final class ListingCommercePolicy
{
    public function canSell(Event $event): bool
    {
        $production = $event->production;
        if (! $production instanceof Production
            || (int) $event->app_id < 1
            || (int) $production->app_id !== (int) $event->app_id
            || (int) $event->owner_user_id < 1
            || (int) $production->user_id < 1
            || $event->isCommunity()) {
            return false;
        }

        if ($event->verification_status === 'legacy_owner_record'
            && $production->verification_status === 'legacy_owner_record'
            && ((int) $event->created_by_user_id !== (int) $production->user_id
                || (int) $event->owner_user_id !== (int) $event->created_by_user_id)) {
            return false;
        }

        return $this->verified($event, (int) $event->created_by_user_id > 0)
            && $this->verified($production, (int) $production->user_id > 0);
    }

    public function assertCanSell(Event $event): void
    {
        abort_unless($this->canSell($event), 403,
            'Esta ficha é informativa. A venda exige titularidade verificada e autorização comercial.');
    }

    private function verified(Event|Production $entity, bool $hasLegacyActor): bool
    {
        if ($entity->ownership_status !== 'verified') {
            return false;
        }
        if ($entity->verification_status === 'manual_approved') {
            return $entity->ownership_verified_at !== null;
        }
        return $entity->verification_status === 'legacy_owner_record'
            && $entity->listing_source === 'legacy_organizer'
            && $hasLegacyActor;
    }
}
