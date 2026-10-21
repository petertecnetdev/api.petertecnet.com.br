<?php

namespace App\Domain\Locations\Services;

use App\Models\BrazilianMunicipality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Facetas geográficas compartilhadas entre os aplicativos do ecossistema.
 * O catálogo de municípios é global; disponibilidade é SEMPRE isolada por app.
 */
final class AvailableCitiesService
{
    private const ENTITIES = ['events', 'productions', 'artists', 'items'];

    public function available(int $appId, string $entity, string $scope = 'upcoming'): array
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw ValidationException::withMessages(['entity' => ['Tipo de conteúdo indisponível.']]);
        }
        if (! in_array($scope, ['upcoming', 'all'], true)) {
            throw ValidationException::withMessages(['scope' => ['Filtro temporal inválido.']]);
        }

        // TTL curto mantém o resultado coerente com publicações e encerramentos.
        $key = "locations:available:v1:{$appId}:{$entity}:{$scope}";
        return Cache::remember($key, now()->addSeconds(60), fn () => $this->build($appId, $entity, $scope));
    }

    private function build(int $appId, string $entity, string $scope): array
    {
        if ($entity === 'events') {
            $query = DB::table('events as e')
                ->where('e.app_id', $appId)
                ->where('e.is_published', true)
                ->where('e.is_cancelled', false)
                ->where(fn ($q) => $q->where('e.is_private', false)->orWhereNull('e.is_private'));
            if ($scope === 'upcoming') {
                $query->where(fn ($q) => $q->whereNull('e.end_date')->orWhere('e.end_date', '>', now()));
            }
        } elseif ($entity === 'productions') {
            $query = DB::table('establishments as e')
                ->where('e.app_id', $appId)->where('e.category', 'production')
                ->where('e.is_published', true)
                ->where(fn ($q) => $q->where('e.is_cancelled', false)->orWhereNull('e.is_cancelled'))
                ->whereNull('e.deleted_at');
        } elseif ($entity === 'artists') {
            $query = DB::table('artists as e')
                ->where('e.app_id', $appId)->where('e.is_published', true)
                ->where('e.is_active', true)->whereNull('e.deleted_at');
        } else {
            // Produtos herdam cidade do estabelecimento de referência. Não se
            // deve confundir a cidade do titular da conta com a oferta local.
            $query = DB::table('items as i')
                ->join('establishments as e', 'e.id', '=', 'i.entity_id')
                ->where('i.app_id', $appId)->where('e.app_id', $appId)
                ->whereIn('i.entity_name', ['establishment', 'Establishment', 'App\\Models\\Establishment'])
                ->where('i.status', true)->whereNull('i.deleted_at')
                ->whereNull('i.archived_at')
                ->where('e.is_published', true)->whereNull('e.deleted_at')
                ->where(fn ($q) => $q->where('e.is_cancelled', false)->orWhereNull('e.is_cancelled'));
        }

        $hasCityId = $entity !== 'artists';
        if ($hasCityId) {
            $query->where(fn ($q) => $q->whereNotNull('e.city_id')
                ->orWhere(fn ($legacy) => $legacy->whereNotNull('e.city')->where('e.city', '<>', '')));
        } else {
            $query->whereNotNull('e.city')->where('e.city', '<>', '');
        }
        if ($hasCityId) {
            $query->selectRaw('e.city_id, e.city, e.uf, COUNT(*) AS total')
                ->groupBy('e.city_id', 'e.city', 'e.uf');
        } else {
            $query->selectRaw('NULL AS city_id, e.city, e.uf, COUNT(*) AS total')
                ->groupBy('e.city', 'e.uf');
        }

        $rows = $query->orderByDesc('total')->limit(1000)->get();
        if ($rows->isEmpty()) return [];

        $known = BrazilianMunicipality::query()
            ->whereIn('ibge_code', $rows->pluck('city_id')->filter()->unique()->all())
            ->get(['ibge_code', 'name', 'uf', 'slug'])->keyBy('ibge_code');

        $names = $rows->pluck('city')->filter()->map(fn ($name) => Str::lower(Str::ascii(trim($name))))
            ->unique()->values()->all();
        $fallback = BrazilianMunicipality::query()
            ->whereIn('uf', $rows->pluck('uf')->filter()->map(fn ($uf) => strtoupper($uf))->unique()->all())
            ->whereIn('normalized_name', $names)
            ->get(['ibge_code', 'name', 'uf', 'slug', 'normalized_name'])
            ->keyBy(fn ($city) => $city->uf.':'.$city->normalized_name);

        $results = [];
        foreach ($rows as $row) {
            $uf = strtoupper(trim((string) $row->uf));
            $legacyName = trim((string) $row->city);
            $normalized = Str::lower(Str::ascii($legacyName));
            $municipality = $known->get($row->city_id) ?? $fallback->get($uf.':'.$normalized);
            $cityName = $municipality?->name ?: $legacyName;
            if ($cityName === '') continue;
            $cityId = $municipality?->ibge_code ? (int) $municipality->ibge_code : null;
            $cityUf = $municipality?->uf ?: $uf;
            $group = $cityId ? 'id:'.$cityId : 'legacy:'.$cityUf.':'.$normalized;
            if (! isset($results[$group])) {
                $results[$group] = [
                    'id' => $cityId,
                    'city_id' => $cityId,
                    'name' => $cityName,
                    'city' => $cityName,
                    'uf' => $cityUf,
                    'region' => $cityUf,
                    'slug' => ($municipality?->slug ?: Str::slug($cityName)).'-'.strtolower($cityUf),
                    'count' => 0,
                    'total' => 0,
                ];
            }
            $results[$group]['total'] += (int) $row->total;
            $results[$group]['count'] = $results[$group]['total'];
        }

        $cities = array_values($results);
        usort($cities, fn ($a, $b) => ($b['total'] <=> $a['total']) ?: strcmp($a['city'], $b['city']));
        return $cities;
    }
}
