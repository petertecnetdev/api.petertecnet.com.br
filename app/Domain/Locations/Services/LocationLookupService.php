<?php

namespace App\Domain\Locations\Services;

use App\Models\BrazilianMunicipality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LocationLookupService
{
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public function __construct(
        private readonly MunicipalityService $municipalities,
        private readonly GooglePlacesService $places,
    ) {}

    public function states(): array
    {
        return self::UFS;
    }

    public function cities(?string $uf, ?string $term): array
    {
        $uf = strtoupper(trim((string) $uf));
        $term = trim((string) $term);

        if ($uf !== '' && ! in_array($uf, self::UFS, true)) {
            throw ValidationException::withMessages(['uf' => ['Selecione uma UF válida.']]);
        }

        if ($uf !== '') {
            $this->ensureStateCached($uf);
        } elseif ($term !== '') {
            $this->ensureBrazilCached();
        } else {
            return [];
        }

        $query = BrazilianMunicipality::query();
        if ($uf !== '') $query->where('uf', $uf);
        if ($term !== '') {
            $query->where('normalized_name', 'like', $this->municipalities->normalizedName($term).'%');
        }

        return $query->orderBy('name')->orderBy('uf')->limit(30)
            ->get(['ibge_code','name','uf','slug'])
            ->map(fn (BrazilianMunicipality $city) => [
                'ibge_code' => $city->ibge_code,
                'name' => $city->name,
                'uf' => $city->uf,
                'slug' => $city->slug,
            ])->all();
    }

    public function cep(string $cep): ?array
    {
        $digits = $this->municipalities->normalizeCep($cep);
        $payload = Cache::remember("locations:cep:{$digits}", now()->addDays(30), function () use ($digits) {
            $response = Http::timeout(4)->retry(1, 150)->acceptJson()->get("https://viacep.com.br/ws/{$digits}/json/");
            if (! $response->successful()) return null;
            $data = $response->json();
            return ! empty($data['erro']) ? null : $data;
        });

        if (! $payload) return null;

        $ibge = (int) ($payload['ibge'] ?? 0);
        $city = $ibge ? BrazilianMunicipality::query()->find($ibge) : null;
        if (! $city && ! empty($payload['uf'])) {
            $this->ensureStateCached(strtoupper($payload['uf']));
            $city = $ibge ? BrazilianMunicipality::query()->find($ibge) : null;
        }

        return [
            'cep' => $digits,
            'street' => trim((string) ($payload['logradouro'] ?? '')),
            'neighborhood' => trim((string) ($payload['bairro'] ?? '')),
            'complement' => trim((string) ($payload['complemento'] ?? '')),
            'city_id' => $city?->ibge_code ?: ($ibge ?: null),
            'city' => $city?->name ?: ($payload['localidade'] ?? null),
            'uf' => $city?->uf ?: strtoupper((string) ($payload['uf'] ?? '')),
        ];
    }

    public function autocompletePlaces(string $query, ?string $sessionToken = null): array
    {
        return $this->places->autocomplete(trim($query), $sessionToken);
    }

    public function place(string $placeId, ?string $sessionToken = null): array
    {
        $place = $this->places->details($placeId, $sessionToken);
        $uf = strtoupper(trim((string) ($place['uf'] ?? '')));
        $cityName = trim((string) ($place['city'] ?? ''));

        if ($uf !== '' && in_array($uf, self::UFS, true)) {
            $this->ensureStateCached($uf);
        }

        $city = null;
        if ($cityName !== '') {
            $city = BrazilianMunicipality::query()
                ->when($uf !== '', fn ($query) => $query->where('uf', $uf))
                ->where('normalized_name', $this->municipalities->normalizedName($cityName))
                ->first();
        }

        $place['city_id'] = $city?->ibge_code;
        if ($city) {
            $place['city'] = $city->name;
            $place['uf'] = $city->uf;
        }

        return $place;
    }

    private function ensureStateCached(string $uf): void
    {
        if (BrazilianMunicipality::query()->where('uf', $uf)->exists()) return;

        Cache::lock("locations:municipalities:{$uf}", 15)->block(5, function () use ($uf) {
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
                'normalized_name' => $this->municipalities->normalizedName($item['nome']),
                'uf' => $uf,
                'slug' => Str::slug($item['nome']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            BrazilianMunicipality::query()->upsert($rows, ['ibge_code'], ['name','normalized_name','uf','slug','updated_at']);
        });
    }

    private function ensureBrazilCached(): void
    {
        if (BrazilianMunicipality::query()->count() >= 5500) return;

        Cache::lock('locations:municipalities:brazil', 30)->block(8, function () {
            if (BrazilianMunicipality::query()->count() >= 5500) return;

            $response = Http::timeout(15)->retry(2, 300)->acceptJson()
                ->get('https://servicodados.ibge.gov.br/api/v1/localidades/municipios', ['orderBy' => 'nome']);

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    'city_id' => ['A lista oficial de cidades está temporariamente indisponível. Tente novamente em instantes.'],
                ]);
            }

            $now = now();
            $rows = collect($response->json())->map(function ($item) use ($now) {
                $uf = strtoupper((string) (
                    data_get($item, 'microrregiao.mesorregiao.UF.sigla')
                    ?: data_get($item, 'regiao-imediata.regiao-intermediaria.UF.sigla')
                ));

                if (! in_array($uf, self::UFS, true)) return null;

                return [
                    'ibge_code' => (int) $item['id'],
                    'name' => trim($item['nome']),
                    'normalized_name' => $this->municipalities->normalizedName($item['nome']),
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
