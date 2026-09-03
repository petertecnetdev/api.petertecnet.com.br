<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationCapabilityReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_generic_contract_works_for_applications_unknown_to_the_codebase(): void
    {
        $applications = collect([
            ['name' => 'Aurora Labs', 'slug' => 'aurora-labs'],
            ['name' => 'Orbit Suite', 'slug' => 'orbit-suite'],
            ['name' => 'Vector Hub', 'slug' => 'vector-hub'],
        ])->map(fn (array $data) => Application::query()->create($data + [
            'is_active' => true,
            'capabilities' => ['catalog', 'commerce'],
        ]));

        foreach ($applications as $application) {
            $this->getJson('/api/v1/apps/'.$application->slug.'/config')
                ->assertOk()
                ->assertJsonPath('application.id', $application->id)
                ->assertJsonPath('application.slug', $application->slug)
                ->assertJsonPath('application.capabilities.0', 'catalog')
                ->assertJsonPath('application.capabilities.1', 'commerce');
        }
    }

    public function test_capabilities_are_driven_by_application_data_not_slug_branches(): void
    {
        $application = Application::query()->create([
            'name' => 'Future Product',
            'slug' => 'future-product-2030',
            'is_active' => true,
            'capabilities' => ['events', 'payments'],
        ]);

        $context = app(ApplicationContext::class);
        $context->set($application);

        try {
            $this->assertTrue($context->supports('events'));
            $this->assertTrue($context->supports('payments'));
            $this->assertFalse($context->supports('commerce'));
            $this->assertFalse($context->supports('crm'));
        } finally {
            $context->clear();
        }
    }

    public function test_legacy_null_capabilities_remain_temporarily_compatible_but_explicit_empty_is_restricted(): void
    {
        $legacy = Application::query()->create([
            'name' => 'Legacy App',
            'slug' => 'legacy-app',
            'is_active' => true,
            'capabilities' => null,
        ]);

        $restricted = Application::query()->create([
            'name' => 'Restricted App',
            'slug' => 'restricted-app',
            'is_active' => true,
            'capabilities' => [],
        ]);

        $context = app(ApplicationContext::class);
        $context->set($legacy);
        $this->assertTrue($context->supports('commerce'));
        $context->set($restricted);
        $this->assertFalse($context->supports('commerce'));
        $context->clear();
    }
}
