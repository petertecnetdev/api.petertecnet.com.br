<?php

namespace App\Domain\Locations\Http\Controllers;

use App\Domain\Locations\Services\MunicipalityService;
use App\Http\Controllers\Controller;
use App\Models\BrazilianMunicipality;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public function states()
    {
        return response()->json(['states' => self::UFS]);
    }

    public function cities(Request $request, MunicipalityService $locations)
    {
        $data = $request->validate([
            'uf' => 'nullable|string|size:2',
            'q' => 'nullable|string|min:2|max:120',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
        ]);

        if (isset($data['lat'], $data['lng'])) {
            return response()->json([
                'cities' => [],
                'location' => $this->reverseCoordinates((float) $data['lat'], (float) $data['lng']),
            ]);
        }

        $uf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $term = trim((string) ($data['q'] ?? ''));

        if ($uf !== '' && ! in_array($uf, self::UFS, true)) {
            throw ValidationException::withMessages(['uf' => ['Selecione uma UF válida.']]);
        }

        if ($uf !== '') {
            $this->ensureStateCached($uf, $locations);
        } elseif ($term !== '') {
            $this->ensureBrazilCached($locations);
        } else {
            return response()->json(['cities' => []]);
        }

        $query = BrazilianMunicipality::query();
        if ($uf !== '') $query->where('uf', $uf);
        if ($term !== '') {
            $query->where('normalized_name', 'like', $locations->normalizedName($term).'%');
        }

        return response()->json([
            'cities' => $query->orderBy('name')->orderBy('uf')->limit(30)->get(['ibge_code','name','uf','slug']),
        ]);
    }

    public function cep(string $cep, MunicipalityService $locations)
    {
        $digits = $locations->normalizeCep($cep);
        $payload = Cache::remember("locations:cep:{$digits}", now()->addDays(30), function () use ($digits) {
            $response = Http::timeout(4)->retry(1, 150)->acceptJson()->get("https://viacep.com.br/ws/{$digits}/json/");
            if (! $response->successful()) return null;
            $data = $response->json();
            return ! empty($data['erro']) ? null : $data;
        });

        if (! $payload) {
            return response()->json([
                'message' => 'Não foi possível localizar este CEP agora. Você pode preencher o endereço manualmente.',
            ], 404);
        }

        $ibge = (int) ($payload['ibge'] ?? 0);
        $city = $ibge ? BrazilianMunicipality::query()->find($ibge) : null;
        if (! $city && ! empty($payload['uf'])) {
            $this->ensureStateCached(strtoupper($payload['uf']), $locations);
            $city = $ibge ? BrazilianMunicipality::query()->find($ibge) : null;
        }

        return response()->json(['address' => [
            'cep' => $digits,
            'street' => trim((string) ($payload['logradouro'] ?? '')),
            'neighborhood' => trim((string) ($payload['bairro'] ?? '')),
            'complement' => trim((string) ($payload['complemento'] ?? '')),
            'city_id' => $city?->ibge_code ?: ($ibge ?: null),
            'city' => $city?->name ?: ($payload['localidade'] ?? null),
            'uf' => $city?->uf ?: strtoupper((string) ($payload['uf'] ?? '')),
        ]]);
    }

    private function reverseCoordinates(float $lat, float $lng): array
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

                $cityLabel = $city !== '' ? $city . ($uf !== '' ? " - {$uf}" : '') : ($state !== '' ? $state : 'Localização atual');
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
        $candidates = [
            $address['ISO3166-2-lvl4'] ?? null,
            $address['ISO3166-2-lvl6'] ?? null,
            $address['state_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = strtoupper(trim((string) $candidate));
            if (preg_match('/(?:BR-)?([A-Z]{2})$/', $value, $matches) && in_array($matches[1], self::UFS, true)) {
                return $matches[1];
            }
        }

        return '';
    }

    private function ensureStateCached(string $uf, MunicipalityService $locations): void
    {
        if (BrazilianMunicipality::query()->where('uf', $uf)->exists()) return;

        Cache::lock("locations:municipalities:{$uf}", 15)->block(5, function () use ($uf, $locations) {
            if (BrazilianMunicipality::query()->where('uf', $uf)->exists()) return;

            $response = Http::timeout(8)->retry(2, 250)->acceptJson()
                ->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios");
            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'city_id' => ['A lista oficial de cidades está temporariamente indisponível. Tente novamente em instantes.'],
                ]);
            }

            $now = now();
            $rows = collect($response->json())->map(fn ($item) => [
                'ibge_code' => (int) $item['id'],
                'name' => trim($item['nome']),
                'normalized_name' => $locations->normalizedName($item['nome']),
                'uf' => $uf,
                'slug' => Str::slug($item['nome']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            BrazilianMunicipality::query()->upsert($rows, ['ibge_code'], ['name','normalized_name','uf','slug','updated_at']);
        });
    }

    private function ensureBrazilCached(MunicipalityService $locations): void
    {
        if (BrazilianMunicipality::query()->count() >= 5500) return;

        Cache::lock('locations:municipalities:brazil', 30)->block(8, function () use ($locations) {
            if (BrazilianMunicipality::query()->count() >= 5500) return;

            $response = Http::timeout(15)->retry(2, 300)->acceptJson()
                ->get('https://servicodados.ibge.gov.br/api/v1/localidades/municipios', ['orderBy' => 'nome']);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'city_id' => ['A lista oficial de cidades está temporariamente indisponível. Tente novamente em instantes.'],
                ]);
            }

            $now = now();
            $rows = collect($response->json())->map(function ($item) use ($locations, $now) {
                $uf = strtoupper((string) (
                    data_get($item, 'microrregiao.mesorregiao.UF.sigla')
                    ?: data_get($item, 'regiao-imediata.regiao-intermediaria.UF.sigla')
                ));

                if (! in_array($uf, self::UFS, true)) return null;

                return [
                    'ibge_code' => (int) $item['id'],
                    'name' => trim($item['nome']),
                    'normalized_name' => $locations->normalizedName($item['nome']),
                    'uf' => $uf,
                    'slug' => Str::slug($item['nome']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter()->values()->all();

            BrazilianMunicipality::query()->upsert($rows, ['ibge_code'], ['name','normalized_name','uf','slug','updated_at']);
        });
    }
}
