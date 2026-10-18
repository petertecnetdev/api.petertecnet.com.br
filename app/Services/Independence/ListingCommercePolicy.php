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
        $appId = (int) $event->app_id;

        if (! $production instanceof Production
            || $appId < 1
            || (int) $production->app_id !== $appId
            || $event->isCommunity()
            || ! $this->verified($event, (int) $event->created_by_user_id > 0)
            || ! $this->verified($production, (int) $production->user_id > 0)) {
            return false;
        }

        // A ficha legada conserva as regras de autoria pré-Independence.
        if ($event->verification_status === 'legacy_owner_record'
            && $production->verification_status === 'legacy_owner_record') {
            return (int) $event->created_by_user_id > 0
                && (int) $event->created_by_user_id === (int) $production->user_id;
        }

        // Aprovação humana de titularidade não altera created_by_user_id/user_id.
        // Usar apenas as concessões auditadas para cruzar a titularidade das DUAS
        // entidades. Isso não equivale à autorização comercial, verificada acima.
        if ($event->verification_status !== 'manual_approved'
            || $production->verification_status !== 'manual_approved') {
            return false;
        }

        $eventOwner = $this->activeOwner($appId, 'event', (int) $event->id);
        $productionOwner = $this->activeOwner($appId, 'production', (int) $production->id);

        return $eventOwner > 0 && $eventOwner === $productionOwner;
    }

    private function activeOwner(int $appId, string $type, int $entityId): int
    {
        if ($entityId < 1) {
            return 0;
        }

        return (int) (\\Illuminate\\Support\\Facades\\DB::table('independence_entity_grants')
            ->where('app_id', $appId)
            ->where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->whereNull('revoked_at')
            ->value('user_id') ?? 0);
    }

    /**
     * Legacy records have NULL ownership markers and retain the pre-existing
     * commerce rules. Once either entity enters the Independence flow, only
     * explicit verified ownership may enable new sales.
     */
    public function allowsLegacyOrVerified(Event $event): bool
    {
        $production = $event->production;
        if (! $production instanceof Production || (int) $production->app_id !== (int) $event->app_id) {
            return false;
        }
        if ($event->ownership_status === null && $production->ownership_status === null) {
            return true;
        }

        return $this->canSell($event);
    }

    public function assertLegacyOrVerified(Event $event): void
    {
        abort_unless($this->allowsLegacyOrVerified($event), 403,
            'Esta ficha é informativa. A venda exige titularidade verificada e autorização comercial.');
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
            // Ownership verification is not merchant onboarding or payment authorization.
            // Only a separate commercial approval for BOTH event and production
            // can unlock new sales for an Independence listing.
            return $entity->ownership_verified_at !== null
                && $entity->commerce_authorized_at !== null;
        }
        return $entity->verification_status === 'legacy_owner_record'
            && $entity->listing_source === 'legacy_organizer'
            && $hasLegacyActor;
    }
}
