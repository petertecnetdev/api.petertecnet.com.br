<?php

declare(strict_types=1);

namespace Tests\Feature\Independence;

use App\Models\Event;
use App\Models\EventItem;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicItemUnclaimedHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ficha_publica_preserva_legado_mas_bloqueia_disponibilidade_apos_unclaimed(): void
    {
        $app = $this->applicationFixture('cutinapp');
        $owner = User::query()->create([
            'first_name' => 'Produtor',
            'email' => 'public-item-unclaimed@example.test',
            'user_name' => 'public-item-unclaimed',
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        $production = Production::query()->create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'user_id' => $owner->id,
            'name' => 'Produção HTTP Independente',
            'slug' => 'producao-http-independente',
            'is_published' => true,
        ]);

        $event = Event::query()->create([
            'app_id' => $app->id,
            'app_slug' => 'cutinapp',
            'production_id' => $production->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Evento HTTP Independente',
            'slug' => 'evento-http-independente',
            'description' => 'Regressão de titularidade na ficha pública.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/ingresso',
            'start_date' => now()->addDays(3),
            'end_date' => now()->addDays(3)->addHours(2),
            'is_published' => false,
        ]);

        DB::table('events')->where('id', $event->id)->update(['is_published' => true]);

        $item = EventItem::query()->create([
            'app_id' => $app->id,
            'event_id' => $event->id,
            'name' => 'Ficha pública de teste',
            'price' => 10,
            'quantity' => 4,
            'is_active' => true,
        ]);

        $url = '/api/v1/apps/cutinapp/events/public/'.$event->slug.'/items/'.$item->id;

        // Registros anteriores à Independence continuam vendáveis.
        $this->assertNull($event->ownership_status);
        $this->assertNull($production->ownership_status);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('item.available', true)
            ->assertJsonPath('item.remaining', 4);

        // A ficha permanece pública, mas não pode anunciar disponibilidade comercial.
        DB::table('events')->where('id', $event->id)->update(['ownership_status' => 'unclaimed']);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('item.available', false)
            ->assertJsonPath('item.remaining', 4);

        // A produção não reivindicada também fecha as vendas, mesmo com evento legado.
        DB::table('events')->where('id', $event->id)->update(['ownership_status' => null]);
        DB::table('establishments')->where('id', $production->id)->update(['ownership_status' => 'unclaimed']);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('item.available', false)
            ->assertJsonPath('item.remaining', 4);

        // O identificador do item não pode atravessar a fronteira entre aplicativos.
        $otherApp = $this->applicationFixture('inkap');
        DB::table('event_items')->where('id', $item->id)->update(['app_id' => $otherApp->id]);
        $this->getJson($url)->assertNotFound();
    }
}
