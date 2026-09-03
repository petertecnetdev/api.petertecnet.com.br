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
        $data = $request->validate(['uf' => 'required|string|size:2', 'q' => 'nullable|string|max:120']);
        $uf = strtoupper($data['uf']);
        if (! in_array($uf, self::UFS, true)) {
            throw ValidationException::withMessages(['uf' => ['Selecione uma UF válida.']]);
        }

        $this->ensureStateCached($uf, $locations);
        $query = BrazilianMunicipality::query()->where('uf', $uf);
        if ($term = trim((string) ($data['q'] ?? ''))) {
            $query->where('normalized_name', 'like', $locations->normalizedName($term).'%');
        }

        return response()->json([
            'cities' => $query->orderBy('name')->limit(30)->get(['ibge_code','name','uf','slug']),
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
}
