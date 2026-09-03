<?php

namespace Tests\Unit;

use App\Domain\Access\Support\ResourceAvailability;
use PHPUnit\Framework\TestCase;

class ResourceAvailabilityTest extends TestCase
{
    public function test_custom_resource_mapping_reuses_public_visibility_contract(): void
    {
        $resource = (object) [
            'uuid' => 77,
            'owner' => 10,
            'enabled' => true,
            'visible' => false,
            'approved' => true,
        ];

        $availability = new ResourceAvailability();
        $result = $availability->evaluate(
            $resource,
            10,
            false,
            true,
            [
                'resource_id' => 'uuid',
                'owner_id' => 'owner',
                'disabled' => fn ($value) => ! $value->enabled,
                'public' => 'visible',
                'approved' => 'approved',
            ]
        );

        $this->assertSame('preview', $result['status']);
        $this->assertSame('not_public', $result['reason']);
        $this->assertTrue($result['viewer']['is_owner']);
        $this->assertSame(77, $result['viewer']['resource_id']);
        $this->assertFalse($result['indexable']);
    }

    public function test_non_owner_cannot_force_private_preview(): void
    {
        $resource = (object) [
            'id' => 8,
            'user_id' => 10,
            'is_cancelled' => false,
            'is_published' => false,
            'is_approved' => true,
        ];

        $result = (new ResourceAvailability())->evaluate($resource, 99, false, true);

        $this->assertSame('restricted', $result['status']);
        $this->assertSame(404, $result['http_status']);
        $this->assertFalse($result['viewer']['can_preview']);
    }
}
