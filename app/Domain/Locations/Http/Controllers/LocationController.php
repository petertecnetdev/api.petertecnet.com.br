<?php

namespace App\Domain\Locations\Http\Controllers;

use App\Domain\Locations\Services\MunicipalityService;
use App\Http\Controllers\Controller;
use App\Models\BrazilianMunicipality;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        ]);

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

    /**
     * Cities with public, discoverable content for this application.
     * A single derived source keeps filters in sync without a redundant city registry.
     */
    public function availableCities(Request $request, ApplicationContext $context): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:productions,events,artists,products',
            'q' => 'nullable|string|max:120',
        ]);
        $type = $data['type'];
        $term = trim((string) ($data['q'] ?? ''));
        $appId = $context->id();
        $cacheKey = 'locations:available:v1:'.$appId.':'.$type.':'.sha1(mb_strtolower($term));

        $cities = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($type, $term, $appId, $context) {
            if ($type === 'productions') {
                $query = DB::table('establishments')
                    ->where('app_id', $appId)
                    ->where('category', 'production')
                    ->where('is_published', true)
                    ->where('is_cancelled', false);
                $column = 'establishments';
            } elseif ($type === 'artists') {
                $query = DB::table('artists')
                    ->where('app_id', $appId)
                    ->where('is_published', true)
                    ->where('is_active', true);
                $column = 'artists';
            } elseif ($type === 'events') {
                $query = DB::table('events')
                    ->where('app_id', $appId)
                    ->where('is_published', true)
                    ->where('is_cancelled', false)
                    ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', now()));
                $column = 'events';
            } else {
                $query = DB::table('items')
                    ->join('establishments', 'establishments.id', '=', 'items.entity_id')
                    ->where('items.entity_name', 'establishment')
                    ->where('items.status', true)
                    ->whereNull('items.archived_at')
                    ->whereNull('items.deleted_at')
                    ->where('establishments.app_id', $appId)
                    ->where('establishments.is_published', true)
                    ->where('establishments.is_cancelled', false);
                if (in_array($context->slug(), config('platform.approval_required_apps', []), true)) {
                    $query->where('establishments.is_approved', true);
                }
                $column = 'establishments';
            }

            $city = $column.'.city';
            $uf = $column.'.uf';
            $query->whereNotNull($city)->whereRaw('TRIM('.$city.") <> ''");
            if ($term !== '') {
                $query->whereRaw('LOWER('.$city.') LIKE ?', ['%'.mb_strtolower($term).'%']);
            }

            return $query
                ->selectRaw('TRIM('.$city.') as city, UPPER(TRIM('.$uf.')) as uf, COUNT(*) as total')
                ->groupByRaw('TRIM('.$city.'), UPPER(TRIM('.$uf.'))')
                ->orderBy('city')
                ->orderBy('uf')
                ->limit(200)
                ->get();
        });

        return response()->json(['cities' => $cities]);
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
