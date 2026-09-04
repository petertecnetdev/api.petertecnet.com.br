<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_undeclared_capabilities_preserve_generic_application_compatibility(): void
    {
        $this->applicationFixture('generic-open', [
            'name' => 'Generic Open',
            'is_active' => true,
            'capabilities' => null,
        ]);

        $this->getJson('/api/v1/apps/generic-open/establishments')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_explicit_capability_allows_matching_domain(): void
    {
        $this->applicationFixture('generic-catalog', [
            'name' => 'Generic Catalog',
            'is_active' => true,
            'capabilities' => ['catalog'],
        ]);

        $this->getJson('/api/v1/apps/generic-catalog/establishments')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_explicit_capabilities_reject_an_unlisted_domain(): void
    {
        $this->applicationFixture('generic-events', [
            'name' => 'Generic Events',
            'is_active' => true,
            'capabilities' => ['events'],
        ]);

        $this->getJson('/api/v1/apps/generic-events/establishments')
            ->assertNotFound()
            ->assertJsonPath('code', 'CAPABILITY_NOT_AVAILABLE')
            ->assertJsonPath('capability', 'catalog');
    }

    public function test_explicit_empty_capabilities_disable_optional_domains(): void
    {
        $this->applicationFixture('generic-restricted', [
            'name' => 'Generic Restricted',
            'is_active' => true,
            'capabilities' => [],
        ]);

        $this->getJson('/api/v1/apps/generic-restricted/establishments')
            ->assertNotFound()
            ->assertJsonPath('code', 'CAPABILITY_NOT_AVAILABLE');
    }

    public function test_declared_capabilities_are_persisted_for_configured_applications(): void
    {
        $raw = DB::table('applications')
            ->where('slug', 'cutinapp')
            ->value('capabilities');

        $capabilities = is_array($raw) ? $raw : json_decode((string) $raw, true);

        $this->assertIsArray($capabilities);
        $this->assertContains('acquisition', $capabilities);

        $this->getJson('/api/v1/apps/cutinapp/config')
            ->assertOk()
            ->assertJsonFragment(['acquisition']);
    }
}
