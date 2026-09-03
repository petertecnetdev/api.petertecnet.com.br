<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Services\EstablishmentDuplicateDetectionService;
use App\Support\TaxIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialDataQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_brazilian_tax_identifier_is_normalized_and_typed(): void
    {
        $this->assertSame('42595409000148', TaxIdentifier::normalizeForCountry('42.595.409/0001-48', 'BR'));
        $this->assertSame('cnpj', TaxIdentifier::type('42.595.409/0001-48', 'BR'));
        $this->assertTrue(TaxIdentifier::isValid('42.595.409/0001-48', 'BR'));
    }

    public function test_establishment_model_keeps_legacy_cnpj_in_sync_with_tax_id(): void
    {
        $establishment = Establishment::create([
            'name' => 'Peter Tecnet Teste',
            'fantasy' => 'Peter Teste',
            'cnpj' => '42.595.409/0001-48',
            'country_code' => 'br',
        ]);

        $this->assertSame('BR', $establishment->country_code);
        $this->assertSame('42595409000148', $establishment->tax_id);
        $this->assertSame('42595409000148', $establishment->cnpj);
        $this->assertSame('cnpj', $establishment->tax_id_type);
    }

    public function test_duplicate_detector_prioritizes_exact_tax_identifier(): void
    {
        $existing = Establishment::create([
            'name' => 'Ferragista Central',
            'fantasy' => 'Ferragista Central',
            'cnpj' => '42.595.409/0001-48',
            'phone' => '(31) 99999-0000',
            'email' => 'contato@example.com',
        ]);

        $match = app(EstablishmentDuplicateDetectionService::class)->detect([
            'name' => 'Outro nome qualquer',
            'tax_id' => '42595409000148',
            'country_code' => 'BR',
        ])->first();

        $this->assertNotNull($match);
        $this->assertSame($existing->id, $match['id']);
        $this->assertSame(100, $match['score']);
        $this->assertContains('same_tax_id', $match['reasons']);
    }
}
