<?php

namespace App\Domain\Locations\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class ReverseGeocodingService
{
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public function resolve(float $lat, float $lng): array
    {
        $roundedLat = round($lat, 4);
        $roundedLng = round($lng, 4);
        $cacheKey = 'locations:reverse:' . hash('sha256', "{$roundedLat}:{$roundedLng}");

        $resolved = Cache::remember($cacheKey, now()->addHours(12), function () use ($lat, $lng) {
            try {
                $response = Http::timeout(5)
                    ->retry(1, 200)
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => (string) config('services.geocoding.user_agent'),
                        'Accept-Language' => 'pt-BR,pt;q=0.9',
                    ])
                    ->get((string) config('services.geocoding.reverse_endpoint'), [
                        'format' => 'jsonv2',
                        'lat' => $lat,
                        'lon' => $lng,
                        'zoom' => 16,
                        'addressdetails' => 1,
                    ]);

                if (! $response->successful()) return null;

                $payload = $response->json();
                $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
                $city = trim((string) (
                    $address['city']
                    ?? $address['town']
                    ?? $address['municipality']
                    ?? $address['village']
                    ?? ''
                ));
                $neighborhood = trim((string) (
                    $address['suburb']
                    ?? $address['neighbourhood']
                    ?? $address['city_district']
                    ?? ''
                ));
                $state = trim((string) ($address['state'] ?? ''));
                $uf = $this->extractUf($address);
                $cityLabel = $city !== ''
                    ? $city . ($uf !== '' ? " - {$uf}" : '')
                    : ($state !== '' ? $state : 'Localização atual');
                $label = $neighborhood !== '' && $neighborhood !== $city
                    ? "{$neighborhood} · {$cityLabel}"
                    : $cityLabel;

                return [
                    'label' => $label,
                    'neighborhood' => $neighborhood ?: null,
                    'city' => $city ?: null,
                    'uf' => $uf ?: null,
                    'state' => $state ?: null,
                ];
            } catch (\Throwable $e) {
                report($e);
                return null;
            }
        });

        return [
            'label' => $resolved['label'] ?? 'Localização atual',
            'neighborhood' => $resolved['neighborhood'] ?? null,
            'city' => $resolved['city'] ?? null,
            'uf' => $resolved['uf'] ?? null,
            'state' => $resolved['state'] ?? null,
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
        ];
    }

    private function extractUf(array $address): string
    {
        foreach ([
            $address['ISO3166-2-lvl4'] ?? null,
            $address['ISO3166-2-lvl6'] ?? null,
            $address['state_code'] ?? null,
        ] as $candidate) {
            $value = strtoupper(trim((string) $candidate));
            if (preg_match('/(?:BR-)?([A-Z]{2})$/', $value, $matches)
                && in_array($matches[1], self::UFS, true)) {
                return $matches[1];
            }
        }

        return '';
    }
}
