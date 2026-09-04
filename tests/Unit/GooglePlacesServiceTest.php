<?php

namespace Tests\Unit;

use App\Domain\Locations\Services\GooglePlacesService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GooglePlacesServiceTest extends TestCase
{
    public function test_autocomplete_returns_compact_place_suggestions(): void
    {
        config(['services.google_maps.places_api_key' => 'test-key']);

        Http::fake([
            'https://places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [[
                    'placePrediction' => [
                        'placeId' => 'place-123',
                        'text' => ['text' => 'La Fyesta Pub, Goiânia - GO, Brasil'],
                        'structuredFormat' => [
                            'mainText' => ['text' => 'La Fyesta Pub'],
                            'secondaryText' => ['text' => 'Goiânia - GO, Brasil'],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GooglePlacesService::class)->autocomplete('La Fyesta', 'session-123');

        $this->assertSame([[
            'place_id' => 'place-123',
            'label' => 'La Fyesta Pub, Goiânia - GO, Brasil',
            'name' => 'La Fyesta Pub',
            'secondary_text' => 'Goiânia - GO, Brasil',
        ]], $result);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://places.googleapis.com/v1/places:autocomplete'
                && $request['input'] === 'La Fyesta'
                && $request['includedRegionCodes'] === ['br']
                && $request['sessionToken'] === 'session-123'
                && $request->hasHeader('X-Goog-Api-Key', 'test-key');
        });
    }

    public function test_details_normalizes_brazilian_address_and_builds_maps_url(): void
    {
        config(['services.google_maps.places_api_key' => 'test-key']);

        Http::fake([
            'https://places.googleapis.com/v1/places/*' => Http::response([
                'id' => 'place-456',
                'formattedAddress' => 'Rua 137, 548 - Setor Marista, Goiânia - GO, 74180-120, Brasil',
                'location' => ['latitude' => -16.6965, 'longitude' => -49.2612],
                'addressComponents' => [
                    ['longText' => '548', 'shortText' => '548', 'types' => ['street_number']],
                    ['longText' => 'Rua 137', 'shortText' => 'R. 137', 'types' => ['route']],
                    ['longText' => 'Setor Marista', 'shortText' => 'Setor Marista', 'types' => ['sublocality_level_1', 'sublocality']],
                    ['longText' => 'Goiânia', 'shortText' => 'Goiânia', 'types' => ['administrative_area_level_2']],
                    ['longText' => 'Goiás', 'shortText' => 'GO', 'types' => ['administrative_area_level_1']],
                    ['longText' => 'Brasil', 'shortText' => 'BR', 'types' => ['country']],
                    ['longText' => '74180-120', 'shortText' => '74180-120', 'types' => ['postal_code']],
                ],
            ], 200),
        ]);

        $place = app(GooglePlacesService::class)->details('place-456', 'session-456');

        $this->assertSame('place-456', $place['place_id']);
        $this->assertSame('Rua 137', $place['address']);
        $this->assertSame('548', $place['address_number']);
        $this->assertSame('Setor Marista', $place['neighborhood']);
        $this->assertSame('Goiânia', $place['city']);
        $this->assertSame('GO', $place['uf']);
        $this->assertSame('74180-120', $place['cep']);
        $this->assertSame(-16.6965, $place['latitude']);
        $this->assertSame(-49.2612, $place['longitude']);
        $this->assertStringContainsString('query_place_id=place-456', $place['google_maps_url']);

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://places.googleapis.com/v1/places/place-456')
                && str_contains($request->url(), 'sessionToken=session-456')
                && $request->hasHeader('X-Goog-Api-Key', 'test-key');
        });
    }
}
