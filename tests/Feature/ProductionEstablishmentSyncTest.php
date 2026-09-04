<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionEstablishmentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_is_kept_in_sync_with_establishment_without_changing_production_identity(): void
    {
        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = User::create([
            'first_name' => 'Production Owner',
            'email' => 'production-establishment-sync@cutinapp.test',
            'user_name' => 'production-establishment-sync',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Casa de Eventos',
            'slug' => 'casa-de-eventos',
            'city' => 'Goiânia',
            'uf' => 'GO',
            'is_published' => true,
            'is_cancelled' => false,
        ])->fresh();

        $this->assertNotNull($production->establishment_id);
        $establishment = Establishment::query()->findOrFail($production->establishment_id);
        $this->assertSame('Casa de Eventos', $establishment->name);
        $this->assertSame('production', $establishment->category);
        $this->assertSame($app->id, $establishment->app_id);

        $production->update(['name' => 'Casa de Eventos Atualizada', 'city' => 'Anápolis']);
        $establishment->refresh();
        $this->assertSame('Casa de Eventos Atualizada', $establishment->name);
        $this->assertSame('Anápolis', $establishment->city);

        $production->delete();
        $this->assertNotNull(Establishment::withTrashed()->find($establishment->id)?->deleted_at);
    }
}
