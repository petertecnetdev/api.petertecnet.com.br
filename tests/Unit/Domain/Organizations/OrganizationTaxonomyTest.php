<?php

namespace Tests\Unit\Domain\Organizations;

use App\Domain\Organizations\Support\OrganizationTaxonomy;
use PHPUnit\Framework\TestCase;

class OrganizationTaxonomyTest extends TestCase
{
    public function test_it_normalizes_legacy_types_to_canonical_types(): void
    {
        $this->assertSame('venue', OrganizationTaxonomy::normalizeType('fixed'));
        $this->assertSame('individual', OrganizationTaxonomy::normalizeType('independent'));
        $this->assertSame('company', OrganizationTaxonomy::normalizeType('production'));
        $this->assertSame('collective', OrganizationTaxonomy::normalizeType('coletivo'));
    }

    public function test_it_preserves_canonical_types(): void
    {
        foreach (array_keys(OrganizationTaxonomy::types()) as $type) {
            $this->assertSame($type, OrganizationTaxonomy::normalizeType($type));
        }
    }

    public function test_it_filters_invalid_roles_and_removes_duplicates(): void
    {
        $this->assertSame(
            ['producer', 'venue'],
            OrganizationTaxonomy::normalizeRoles(['producer', 'producer', 'invalid', 'venue'], 'company')
        );
    }

    public function test_it_uses_type_defaults_when_roles_are_missing(): void
    {
        $this->assertSame(['venue'], OrganizationTaxonomy::normalizeRoles([], 'venue'));
        $this->assertSame(['producer', 'organizer'], OrganizationTaxonomy::normalizeRoles(null, 'collective'));
    }
}
