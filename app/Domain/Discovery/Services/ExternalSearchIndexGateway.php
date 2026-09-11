<?php

namespace App\Domain\Discovery\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class ExternalSearchIndexGateway
{
    public function enabled(): bool
    {
        return in_array($this->driver(), ['meilisearch', 'typesense', 'opensearch'], true)
            && trim((string) config('discovery_search.endpoint')) !== '';
    }

    public function candidateIds(string $type, string $query, array $filters = [], int $limit = 100): ?Collection
    {
        if (! $this->enabled() || trim($query) === '') {
            return null;
        }

        try {
            return match ($this->driver()) {
                'meilisearch' => $this->meiliSearch($type, $query, $filters, $limit),
                'typesense' => $this->typesenseSearch($type, $query, $filters, $limit),
                'opensearch' => $this->openSearch($type, $query, $filters, $limit),
                default => null,
            };
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function replaceDocuments(string $type, Collection $documents): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            return match ($this->driver()) {
                'meilisearch' => $this->replaceMeili($type, $documents),
                'typesense' => $this->replaceTypesense($type, $documents),
                'opensearch' => $this->replaceOpenSearch($type, $documents),
                default => false,
            };
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function meiliSearch(string $type, string $query, array $filters, int $limit): Collection
    {
        $response = $this->request()->post($this->endpoint().'/indexes/'.$this->index($type).'/search', [
            'q' => $query,
            'limit' => min(200, $limit),
            'filter' => $this->meiliFilters($filters),
            'attributesToRetrieve' => ['id'],
        ])->throw()->json();

        return collect($response['hits'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
    }

    private function typesenseSearch(string $type, string $query, array $filters, int $limit): Collection
    {
        $params = [
            'q' => $query,
            'query_by' => 'title,subtitle,search_text',
            'per_page' => min(200, $limit),
        ];
        $filterBy = $this->typesenseFilters($filters);
        if ($filterBy !== '') $params['filter_by'] = $filterBy;

        $response = $this->request()
            ->withHeaders(['X-TYPESENSE-API-KEY' => (string) config('discovery_search.key')])
            ->get($this->endpoint().'/collections/'.$this->index($type).'/documents/search', $params)
            ->throw()
            ->json();

        return collect($response['hits'] ?? [])->pluck('document.id')->map(fn ($id) => (int) $id)->filter()->values();
    }

    private function openSearch(string $type, string $query, array $filters, int $limit): Collection
    {
        $must = [[
            'multi_match' => [
                'query' => $query,
                'fields' => ['title^5', 'subtitle^2', 'search_text'],
                'fuzziness' => 'AUTO',
            ],
        ]];

        foreach (array_filter(['city' => $filters['city'] ?? null, 'uf' => $filters['uf'] ?? null]) as $field => $value) {
            $must[] = ['term' => [$field.'.keyword' => $value]];
        }

        $response = $this->request()
            ->post($this->endpoint().'/'.$this->index($type).'/_search', [
                'size' => min(200, $limit),
                '_source' => ['id'],
                'query' => ['bool' => ['must' => $must]],
            ])
            ->throw()
            ->json();

        return collect($response['hits']['hits'] ?? [])->pluck('_source.id')->map(fn ($id) => (int) $id)->filter()->values();
    }

    private function replaceMeili(string $type, Collection $documents): bool
    {
        $url = $this->endpoint().'/indexes/'.$this->index($type).'/documents';
        $this->request()->delete($url)->throw();
        if ($documents->isNotEmpty()) {
            $this->request()->post($url.'?primaryKey=id', $documents->values()->all())->throw();
        }

        return true;
    }

    private function replaceTypesense(string $type, Collection $documents): bool
    {
        $headers = ['X-TYPESENSE-API-KEY' => (string) config('discovery_search.key')];
        if ($documents->isEmpty()) return true;

        $body = $documents->map(fn ($document) => json_encode($document, JSON_UNESCAPED_UNICODE))->implode("\n");
        Http::withHeaders($headers)
            ->timeout($this->timeout())
            ->withBody($body, 'text/plain')
            ->post($this->endpoint().'/collections/'.$this->index($type).'/documents/import?action=upsert')
            ->throw();

        return true;
    }

    private function replaceOpenSearch(string $type, Collection $documents): bool
    {
        if ($documents->isEmpty()) return true;

        $body = $documents->flatMap(fn ($document) => [
            json_encode(['index' => ['_index' => $this->index($type), '_id' => $document['id']]], JSON_UNESCAPED_UNICODE),
            json_encode($document, JSON_UNESCAPED_UNICODE),
        ])->implode("\n")."\n";

        Http::withToken((string) config('discovery_search.key'))
            ->timeout($this->timeout())
            ->withBody($body, 'application/x-ndjson')
            ->post($this->endpoint().'/_bulk')
            ->throw();

        return true;
    }

    private function request()
    {
        $request = Http::acceptJson()->timeout($this->timeout());
        $key = trim((string) config('discovery_search.key'));

        return $key !== '' && $this->driver() !== 'typesense' ? $request->withToken($key) : $request;
    }

    private function meiliFilters(array $filters): ?array
    {
        $result = [];
        if (! empty($filters['city'])) $result[] = 'city = "'.addslashes((string) $filters['city']).'"';
        if (! empty($filters['uf'])) $result[] = 'uf = "'.addslashes((string) $filters['uf']).'"';

        return $result ?: null;
    }

    private function typesenseFilters(array $filters): string
    {
        $result = [];
        if (! empty($filters['city'])) $result[] = 'city:='.str_replace(['&&', ':'], '', (string) $filters['city']);
        if (! empty($filters['uf'])) $result[] = 'uf:='.str_replace(['&&', ':'], '', (string) $filters['uf']);

        return implode(' && ', $result);
    }

    private function driver(): string
    {
        return strtolower(trim((string) config('discovery_search.driver', 'database')));
    }

    private function endpoint(): string
    {
        return rtrim((string) config('discovery_search.endpoint'), '/');
    }

    private function index(string $type): string
    {
        return trim((string) config('discovery_search.index_prefix', 'peter'), '-_').'_'.preg_replace('/[^a-z0-9_-]+/', '_', strtolower($type));
    }

    private function timeout(): int
    {
        return max(1, min(15, (int) config('discovery_search.timeout', 3)));
    }
}
