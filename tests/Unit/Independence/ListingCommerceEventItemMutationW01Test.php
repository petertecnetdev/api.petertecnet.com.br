<?php

declare(strict_types=1);

namespace Tests\Unit\Independence;

use App\Domain\Commerce\Http\Controllers\EventCommerceController;
use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ListingCommerceEventItemMutationW01Test extends TestCase
{
    private function eventWithProduction(?string $eventOwnership, ?string $productionOwnership, int $productionAppId = 1): Event
    {
        $event = new Event();
        $event->forceFill([
            'id' => 20,
            'app_id' => 1,
            'production_id' => 10,
            'kind' => 'commercial',
            'ownership_status' => $eventOwnership,
        ]);

        $production = new Production();
        $production->forceFill([
            'id' => 10,
            'app_id' => $productionAppId,
            'ownership_status' => $productionOwnership,
        ]);
        $event->setRelation('production', $production);

        return $event;
    }

    public function test_item_comercial_nega_producao_nao_reivindicada_ou_de_outro_aplicativo(): void
    {
        $policy = new ListingCommercePolicy();

        self::assertFalse($policy->allowsLegacyOrVerified(
            $this->eventWithProduction('verified', 'unclaimed')
        ));
        self::assertFalse($policy->allowsLegacyOrVerified(
            $this->eventWithProduction(null, null, 2)
        ));
        self::assertTrue($policy->allowsLegacyOrVerified(
            $this->eventWithProduction(null, null)
        ));
    }

    public function test_endpoint_verifica_autorizacao_antes_de_gravar_item(): void
    {
        $method = new ReflectionMethod(EventCommerceController::class, 'upsertEventItem');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));

        $gate = strpos($body, '$this->listingCommerce->assertLegacyOrVerified($event);');
        $save = strpos($body, '$item->fill($data)->save();');

        self::assertNotFalse($gate, 'O endpoint precisa verificar a titularidade no servidor.');
        self::assertNotFalse($save, 'O teste deve acompanhar a persistência real do item.');
        self::assertLessThan($save, $gate, 'A autorização deve anteceder qualquer gravação.');
    }
}
