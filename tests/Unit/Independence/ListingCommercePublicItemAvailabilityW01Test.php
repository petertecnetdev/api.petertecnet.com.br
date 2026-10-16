<?php

declare(strict_types=1);

namespace Tests\Unit\Independence;

use App\Domain\Commerce\Http\Controllers\EventCommerceController;
use App\Models\Event;
use App\Models\Production;
use App\Services\Independence\ListingCommercePolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ListingCommercePublicItemAvailabilityW01Test extends TestCase
{
    private function eventWithProduction(?string $eventStatus, ?string $productionStatus, int $productionAppId = 1): Event
    {
        $event = new Event();
        $event->forceFill([
            'id' => 20,
            'app_id' => 1,
            'production_id' => 10,
            'kind' => 'commercial',
            'ownership_status' => $eventStatus,
        ]);

        $production = new Production();
        $production->forceFill([
            'id' => 10,
            'app_id' => $productionAppId,
            'ownership_status' => $productionStatus,
        ]);
        $event->setRelation('production', $production);

        return $event;
    }

    public function test_disponibilidade_respeita_legado_e_bloqueia_fichas_nao_verificadas_e_cross_app(): void
    {
        $policy = new ListingCommercePolicy();

        self::assertTrue($policy->allowsLegacyOrVerified($this->eventWithProduction(null, null)));
        self::assertFalse($policy->allowsLegacyOrVerified($this->eventWithProduction('unclaimed', 'unclaimed')));
        self::assertFalse($policy->allowsLegacyOrVerified($this->eventWithProduction('verified', 'pending_claim')));
        self::assertFalse($policy->allowsLegacyOrVerified($this->eventWithProduction(null, null, 2)));
    }

    public function test_ficha_publica_consulta_politica_antes_de_calcular_disponibilidade(): void
    {
        $method = new ReflectionMethod(EventCommerceController::class, 'publicItem');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $gate = strpos($body, '$this->listingCommerce->allowsLegacyOrVerified($event)');
        $availability = strpos($body, '$this->appendItemAvailability($eventItem, $salesClosed);');

        self::assertNotFalse($gate, 'A ficha pública deve respeitar a política de titularidade.');
        self::assertNotFalse($availability, 'A disponibilidade deve utilizar o bloqueio calculado.');
        self::assertLessThan($availability, $gate, 'A política deve ser avaliada antes da disponibilidade.');
        self::assertStringContainsString(
            '$salesClosed = $event->salesClosed() || ! $this->listingCommerce->allowsLegacyOrVerified($event);',
            $body
        );
    }

    public function test_desativacao_de_item_permanece_disponivel_para_mitigacao(): void
    {
        $method = new ReflectionMethod(EventCommerceController::class, 'deleteEventItem');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString("\$item->update(['is_active' => false]);", $body);
        self::assertStringNotContainsString('assertLegacyOrVerified($event)', $body);
    }
}
