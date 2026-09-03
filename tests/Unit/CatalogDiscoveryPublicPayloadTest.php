<?php

namespace Tests\Unit;

use App\Domain\Catalog\Http\Controllers\CatalogDiscoveryController;
use App\Models\Establishment;
use App\Models\File;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CatalogDiscoveryPublicPayloadTest extends TestCase
{
    public function test_public_establishment_payload_does_not_serialize_administrative_metrics(): void
    {
        $file = new File([
            'id' => 10,
            'entity_id' => 7,
            'entity_name' => 'establishment',
            'public_url' => 'https://example.test/logo.webp',
        ]);

        $establishment = new Establishment([
            'name' => 'Empresa de teste',
            'fantasy' => 'Empresa de teste',
        ]);
        $establishment->setAttribute('id', 7);
        $establishment->setRelation('files', new Collection([$file]));

        $prepared = $this->invoke('preparePublicEstablishment', $establishment);
        $payload = $prepared->toArray();

        $this->assertArrayNotHasKey('metrics', $payload);
        $this->assertArrayHasKey('files', $payload);
        $this->assertArrayNotHasKey('metrics', $payload['files'][0]);
        $this->assertArrayNotHasKey('interaction_summary', $payload['files'][0]);
    }

    public function test_public_item_payload_keeps_image_but_does_not_serialize_file_or_establishment_metrics(): void
    {
        $file = new File([
            'id' => 11,
            'entity_id' => 22,
            'entity_name' => 'item',
            'public_url' => 'https://example.test/item.webp',
            'is_primary' => true,
        ]);

        $establishment = new Establishment([
            'name' => 'Empresa de teste',
            'fantasy' => 'Empresa de teste',
        ]);
        $establishment->setAttribute('id', 7);

        $item = new Item([
            'name' => 'Produto de teste',
            'entity_id' => 7,
            'entity_name' => 'establishment',
        ]);
        $item->setAttribute('id', 22);
        $item->setRelation('files', new Collection([$file]));
        $item->setRelation('establishment', $establishment);

        $prepared = $this->invoke('preparePublicItem', $item);
        $payload = $prepared->toArray();

        $this->assertSame('https://example.test/item.webp', $payload['image_url']);
        $this->assertArrayNotHasKey('metrics', $payload['files'][0]);
        $this->assertArrayNotHasKey('interaction_summary', $payload['files'][0]);
        $this->assertArrayNotHasKey('metrics', $payload['establishment']);
    }

    private function invoke(string $method, object $argument): object
    {
        $controller = new CatalogDiscoveryController(new ApplicationContext());
        $reflection = new ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller, $argument);
    }
}
