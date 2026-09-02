<?php

namespace Tests\Feature;

use App\Domain\Events\Services\AdmissionService;
use App\Models\AdmissionType;
use App\Models\Application;
use App\Models\Event;
use App\Models\PeopleProfile;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeterPlatformArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_domain_contains_no_product_names(): void
    {
        $forbidden = ['cutinapp', 'rasoio', 'plat', 'payflow', 'inkap', 'laora', 'nexus'];

        foreach (File::allFiles(app_path('Domain')) as $file) {
            $content = strtolower(File::get($file->getPathname()));
            foreach ($forbidden as $product) {
                $this->assertStringNotContainsString(
                    $product,
                    $content,
                    "Generic domain file {$file->getRelativePathname()} is coupled to product {$product}."
                );
            }
        }
    }

    public function test_platform_native_people_are_automatically_isolated_by_application_context(): void
    {
        $a = $this->application('App A', 'app-a');
        $b = $this->application('App B', 'app-b');
        $context = app(ApplicationContext::class);

        $context->set($a);
        $first = PeopleProfile::create(['display_name' => 'Pessoa A', 'kind' => 'person', 'status' => 'active']);
        $this->assertSame($a->id, $first->application_id);
        $context->clear();

        $context->set($b);
        PeopleProfile::create(['display_name' => 'Pessoa B', 'kind' => 'person', 'status' => 'active']);
        $this->assertSame(['Pessoa B'], PeopleProfile::query()->pluck('display_name')->all());
        $context->clear();

        $context->set($a);
        $this->assertSame(['Pessoa A'], PeopleProfile::query()->pluck('display_name')->all());
        $context->clear();
    }

    public function test_admission_credentials_are_application_isolated_and_raw_secret_is_not_persisted(): void
    {
        $app = $this->application('Admissions', 'admissions-test');
        $user = $this->user();
        $production = Production::query()->forceCreate([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'user_id' => $user->id,
            'name' => 'Produção Teste',
        ]);
        $event = Event::query()->forceCreate([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'production_id' => $production->id,
            'user_id' => $user->id,
            'title' => 'Evento Teste',
            'slug' => 'evento-teste',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDays(2),
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $context = app(ApplicationContext::class);
        $context->set($app);
        $type = app(AdmissionService::class)->createType([
            'event_id' => $event->id,
            'name' => 'Entrada',
            'price' => 10,
            'capacity' => 10,
        ]);
        $credential = app(AdmissionService::class)->issue($type, $user->id);
        $raw = $credential->getAttribute('credential_code');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString(explode('.', $raw, 2)[1], (string) $credential->code_hash);
        $this->assertDatabaseMissing('admission_credentials', ['code_hash' => explode('.', $raw, 2)[1]]);
        $context->clear();
    }

    private function application(string $name, string $slug): Application
    {
        return Application::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function user(): User
    {
        return User::create([
            'first_name' => 'Platform',
            'email' => uniqid('platform-', true) . '@example.test',
            'user_name' => uniqid('platform-', true),
            'password' => Hash::make('Test1234!'),
        ]);
    }
}
