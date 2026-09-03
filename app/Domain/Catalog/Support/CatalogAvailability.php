<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Access\Support\ResourceAvailability;
use App\Models\Establishment;

final class CatalogAvailability
{
    private ResourceAvailability $resources;

    public function __construct(?ResourceAvailability $resources = null)
    {
        $this->resources = $resources ?? new ResourceAvailability();
    }

    public function evaluate(
        ?Establishment $establishment,
        ?int $viewerId = null,
        bool $approvalRequired = false,
        bool $previewRequested = false
    ): array {
        return $this->resources->evaluate(
            $establishment,
            $viewerId,
            $approvalRequired,
            $previewRequested,
            [
                'owner_id' => 'user_id',
                'resource_id' => 'id',
                'disabled' => 'is_cancelled',
                'public' => 'is_published',
                'approved' => 'is_approved',
            ]
        );
    }
}
