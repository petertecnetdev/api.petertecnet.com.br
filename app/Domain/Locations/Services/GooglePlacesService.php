<?php

namespace App\Domain\Locations\Services;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class GooglePlacesService
{
    private const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    public function autocomplete(string $query, ?string $sessionToken = null): array
    {
        $payload = [
            'input' => trim($query),
            'includedRegionCodes' => ['br'],
            'languageCode' => 'pt-BR',
            'regionCode' => 'br',
        ];

        if ($sessionToken) {
            $payload['sessionToken'] = $sessionToken;
        }

        $response = Http::timeout($this->timeout())
            ->retry(1, 150)
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey(),
                'X-Goog-FieldMask' => implode(',', [
                    'suggestions.placePrediction.placeId',
                    'suggestions.placePrediction.text.text',
                    'suggestions.placePrediction.structuredFormat.mainText.text',
                    'suggestions.placePrediction.structuredFormat.secondaryText.text',
                ]),
            ])
            ->post(self::AUTOCOMPLETE_URL, $payload);

        if (! $response->successful()) {
            $this->providerFailure($response->status());
        }

        return collect($response->json('suggestions', []))
            ->map(fn ($item) => data_get($item, 'placePrediction'))
            ->filter(fn ($prediction) => is_array($prediction) && ! empty($prediction['placeId']))
            ->take(8)
            ->map(fn ($prediction) => [
                'place_id' => (string) $prediction['placeId'],
                'label' => trim((string) data_get($prediction, 'text.text')),
                'name' => trim((string) data_get($prediction, 'structuredFormat.mainText.text')),
                'secondary_text' => trim((string) data_get($prediction, 'structuredFormat.secondaryText.text')),
            ])
            ->values()
            ->all();
    }

    public function details(string $placeId, ?string $sessionToken = null): array
    {
        $params = [
            'languageCode' => 'pt-BR',
            'regionCode' => 'br',
        ];
        if ($sessionToken) {
            $params['sessionToken'] = $sessionToken;
        }

        $response = Http::timeout($this->timeout())
            ->retry(1, 150)
            ->acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $this->apiKey(),
                'X-Goog-FieldMask' => 'id,formattedAddress,location,addressComponents',
            ])
            ->get(self::DETAILS_URL . rawurlencode($placeId), $params);

        if (! $response->successful()) {
            $this->providerFailure($response->status());
        }

        $data = $response->json();
        $components = collect($data['addressComponents'] ?? []);
        $component = function (array $types, bool $short = false) use ($components): ?string {
            foreach ($types as $type) {
                $match = $components->first(fn ($item) => in_array($type, $item['types'] ?? [], true));
                if ($match) {
                    $value = $short ? ($match['shortText'] ?? null) : ($match['longText'] ?? null);
                    if ($value !== null && trim((string) $value) !== '') return trim((string) $value);
                }
            }
            return null;
        };

        $id = (string) ($data['id'] ?? $placeId);
        $formatted = trim((string) ($data['formattedAddress'] ?? ''));
        $latitude = data_get($data, 'location.latitude');
        $longitude = data_get($data, 'location.longitude');
        $countryCode = strtoupper((string) ($component(['country'], true) ?? ''));

        if ($countryCode !== '' && $countryCode !== 'BR') {
            throw new HttpException(422, 'Selecione um local no Brasil.');
        }

        return [
            'place_id' => $id,
            'formatted_address' => $formatted ?: null,
            'address' => $component(['route']),
            'address_number' => $component(['street_number']),
            'neighborhood' => $component(['sublocality_level_1', 'sublocality', 'neighborhood']),
            'city' => $component(['administrative_area_level_2', 'locality', 'postal_town']),
            'uf' => strtoupper((string) ($component(['administrative_area_level_1'], true) ?? '')) ?: null,
            'country' => $component(['country']),
            'country_code' => $countryCode ?: 'BR',
            'cep' => $component(['postal_code']),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'google_maps_url' => $this->googleMapsUrl($id, $formatted),
        ];
    }

    private function googleMapsUrl(string $placeId, string $formattedAddress): string
    {
        $query = $formattedAddress !== '' ? $formattedAddress : $placeId;
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query)
            . '&query_place_id=' . rawurlencode($placeId);
    }

    private function apiKey(): string
    {
        $key = trim((string) config('services.google_maps.places_api_key'));
        if ($key === '') {
            throw new HttpException(503, 'A busca de locais está temporariamente indisponível. O endereço ainda pode ser preenchido por CEP ou manualmente.');
        }
        return $key;
    }

    private function timeout(): int
    {
        return max(2, min(10, (int) config('services.google_maps.timeout', 5)));
    }

    private function providerFailure(int $status): never
    {
        report(new \RuntimeException("Google Places request failed with HTTP {$status}."));
        throw new HttpException(503, 'Não foi possível consultar o Google Maps agora. Tente novamente ou use o CEP.');
    }
}
