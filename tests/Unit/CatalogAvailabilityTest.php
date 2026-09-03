<?php

namespace Tests\Unit;

use App\Domain\Catalog\Support\CatalogAvailability;
use App\Models\Establishment;
use PHPUnit\Framework\TestCase;

class CatalogAvailabilityTest extends TestCase
{
    private CatalogAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = new CatalogAvailability();
    }

    public function test_missing_resource_is_not_found_and_not_indexable(): void
    {
        $result = $this->availability->evaluate(null);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame('not_found', $result['reason']);
        $this->assertSame(404, $result['http_status']);
        $this->assertFalse($result['indexable']);
        $this->assertFalse($result['is_public']);
    }

    public function test_unpublished_resource_is_restricted_for_visitors(): void
    {
        $establishment = $this->establishment([
            'is_published' => false,
            'is_approved' => true,
            'is_cancelled' => false,
        ]);

        $result = $this->availability->evaluate($establishment, 99);

        $this->assertSame('restricted', $result['status']);
        $this->assertSame('not_public', $result['reason']);
        $this->assertSame(404, $result['http_status']);
        $this->assertFalse($result['viewer']['is_owner']);
        $this->assertFalse($result['viewer']['can_preview']);
    }

    public function test_owner_can_preview_unpublished_resource_without_making_it_indexable(): void
    {
        $establishment = $this->establishment([
            'is_published' => false,
            'is_approved' => true,
            'is_cancelled' => false,
        ]);

        $result = $this->availability->evaluate($establishment, 10, false, true);

        $this->assertSame('preview', $result['status']);
        $this->assertSame('not_public', $result['reason']);
        $this->assertSame(200, $result['http_status']);
        $this->assertTrue($result['preview']);
        $this->assertFalse($result['indexable']);
        $this->assertTrue($result['viewer']['is_owner']);
        $this->assertTrue($result['viewer']['can_manage']);
        $this->assertTrue($result['viewer']['can_preview']);
        $this->assertSame(123, $result['viewer']['resource_id']);
    }

    public function test_public_resource_is_indexable(): void
    {
        $establishment = $this->establishment([
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
        ]);

        $result = $this->availability->evaluate($establishment, null, true);

        $this->assertSame('public', $result['status']);
        $this->assertSame(200, $result['http_status']);
        $this->assertTrue($result['is_public']);
        $this->assertTrue($result['indexable']);
    }

    public function test_pending_approval_is_restricted_when_application_requires_approval(): void
    {
        $establishment = $this->establishment([
            'is_published' => true,
            'is_approved' => false,
            'is_cancelled' => false,
        ]);

        $result = $this->availability->evaluate($establishment, null, true);

        $this->assertSame('restricted', $result['status']);
        $this->assertSame('pending_approval', $result['reason']);
        $this->assertSame(404, $result['http_status']);
    }

    private function establishment(array $attributes): Establishment
    {
        $establishment = new Establishment(array_merge([
            'name' => 'Empresa teste',
            'user_id' => 10,
        ], $attributes));
        $establishment->id = 123;

        return $establishment;
    }
}
