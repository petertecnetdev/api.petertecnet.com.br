<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EventPurchaseOptionsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_options_accept_legacy_public_event_with_null_privacy(): void
    {
        $app = $this->applicationFixture('cutinapp', [
            'name' => 'Cutinapp',
            'is_active' => true,
        ]);

        $profile = Profile::create([
            'name' => 'Produtor teste privacy',
            'permissions' => [],
        ]);

        $owner = User::create([
            'first_name' => 'Produtor',
            'email' => 'purchase-privacy@example.test',
            'user_name' => 'purchase-privacy',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);

        $productionId = DB::table('establishments')->insertGetId([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'name' => 'Produção privacy',
            'slug' => 'producao-privacy',
            'type' => 'production',
            'category' => 'production',
            'establishment_type' => 'production',
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = Event::withoutEvents(fn () => Event::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $productionId,
            'title' => 'Evento público legado',
            'description' => 'Evento criado antes da normalização de privacidade.',
            'event_format' => 'in_person',
            'address' => 'Rua Teste, 100',
            'start_date' => now()->addDay()->setTime(20, 0),
            'end_date' => now()->addDay()->setTime(23, 59),
            'slug' => 'evento-publico-privacy-null',
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'is_private' => null,
        ]));

        $this->getJson('/api/v1/apps/cutinapp/events/public/'.$event->slug)
            ->assertOk()
            ->assertJsonPath('event.id', $event->id);

        $this->getJson('/api/v1/apps/cutinapp/events/public/'.$event->slug.'/purchase-options')
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('available_dates.0.event_id', $event->id);
    }
}
