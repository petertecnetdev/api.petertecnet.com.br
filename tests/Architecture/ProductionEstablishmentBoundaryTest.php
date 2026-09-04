<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ProductionEstablishmentBoundaryTest extends TestCase
{
    public function test_production_specific_state_has_a_specialized_profile(): void
    {
        $profile = file_get_contents(base_path('app/Models/EventProducerProfile.php'));
        $production = file_get_contents(base_path('app/Models/Production.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_04_225500_create_event_producer_profiles.php'));

        self::assertStringContainsString("protected $table", $profile);
        self::assertStringContainsString('event_producer_profiles', $migration);
        self::assertStringContainsString('eventProducerProfile', $production);
        self::assertStringContainsString('syncEventProducerProfile', $production);
    }

    public function test_generic_establishment_contract_does_not_expose_production_profile_fields(): void
    {
        $establishment = file_get_contents(base_path('app/Models/Establishment.php'));

        foreach ([
            "'capacity'",
            "'start_date'",
            "'end_date'",
            "'ticket_price_min'",
            "'ticket_price_max'",
            "'total_tickets_sold'",
            "'total_tickets_available'",
        ] as $productionOnlyField) {
            self::assertStringNotContainsString(
                $productionOnlyField,
                $this->fillableBlock($establishment),
                'Production-specific fields must stay out of the generic Establishment fillable contract.',
            );
        }
    }

    private function fillableBlock(string $source): string
    {
        preg_match('/protected $fillable\s*=\s*\[(.*?)\];/s', $source, $matches);

        return $matches[1] ?? '';
    }
}
