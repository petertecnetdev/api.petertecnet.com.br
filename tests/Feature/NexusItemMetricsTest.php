<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexusItemMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_item_view_metrics_for_own_establishment(): void
    {
        $app = Application::factory()->create();
        $owner = User::factory()->create();
        $establishment = Establishment::factory()->create([
            'app_id' => $app->id,
            'user_id' => $owner->id,
        ]);
        $item = Item::factory()->create([
            'app_id' => $app->id,
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $owner->id,
        ]);

        Interaction::factory()->count(3)->create([
            'interaction_type' => 'view',
            'entity_type' => Item::class,
            'entity_id' => $item->id,
        ]);

        $token = auth()->login($owner);

        $response = $this->withToken($token)->getJson('/api/account/item-metrics?' . http_build_query([
            'app_id' => $app->id,
            'establishment_id' => $establishment->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('establishment_id', $establishment->id)
            ->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('items.0.total_views', 3);
    }
}
