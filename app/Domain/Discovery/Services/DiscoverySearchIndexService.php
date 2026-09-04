<?php

namespace App\Domain\Discovery\Services;

use App\Domain\Catalog\Services\PublicCatalogQuery;
use App\Models\Application;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DiscoverySearchIndexService
{
    public function __construct(private readonly PublicCatalogQuery $publicCatalog)
    {
    }

    public function rebuild(): int
    {
        $rows = collect();
        $applications = Application::query()
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (Application $application) => (int) $application->id);

        $applications->each(function (Application $application) use ($rows) {
            $rows->push($this->document(
                'application',
                (int) $application->id,
                (int) $application->id,
                (string) $application->name,
                $application->description,
                null,
                null,
                '/plataformas/' . $application->slug,
                30,
                ['slug' => $application->slug]
            ));
        });

        ContentEntry::query()
            ->published()
            ->limit(4000)
            ->get()
            ->each(function (ContentEntry $entry) use ($rows, $applications) {
                if ($entry->application_id && ! $applications->has((int) $entry->application_id)) {
                    return;
                }

                $rows->push($this->document(
                    'content',
                    (int) $entry->id,
                    $entry->application_id ? (int) $entry->application_id : null,
                    (string) $entry->title,
                    $entry->excerpt ?: Str::limit(strip_tags((string) $entry->content), 320),
                    $entry->category,
                    null,
                    '/blog/' . $entry->slug,
                    24,
                    [
                        'cluster' => $entry->cluster,
                        'tags' => $entry->tags,
                        'search_intent' => $entry->search_intent,
                    ]
                ));
            });

        Establishment::query()
            ->with('applications:id,slug,is_active')
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->limit(5000)
            ->get()
            ->each(function (Establishment $establishment) use ($rows, $applications) {
                foreach ($this->publicCatalog->publicApplicationIds($establishment, $applications) as $applicationId) {
                    $rows->push($this->document(
                        'establishment',
                        (int) $establishment->id,
                        $applicationId,
                        (string) ($establishment->fantasy ?: $establishment->name),
                        $establishment->description,
                        $establishment->category ?: $establishment->type,
                        $establishment->city,
                        '/empresas/' . $establishment->slug,
                        22,
                        ['uf' => $establishment->uf]
                    ));
                }
            });

        Item::query()
            ->with([
                'establishment' => fn ($query) => $query->with('applications:id,slug,is_active'),
            ])
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', fn ($query) => $query
                ->where('is_cancelled', false)
                ->where('is_published', true))
            ->limit(12000)
            ->get()
            ->each(function (Item $item) use ($rows, $applications) {
                $establishment = $item->establishment;
                if (! $establishment) {
                    return;
                }

                foreach ($this->publicCatalog->publicApplicationIds($establishment, $applications) as $applicationId) {
                    $rows->push($this->document(
                        'item',
                        (int) $item->id,
                        $applicationId,
                        (string) $item->name,
                        $item->description,
                        $item->category ?: $item->type,
                        $establishment->city,
                        '/solucoes/' . ($item->slug ?: $item->id),
                        28,
                        [
                            'price' => $item->price,
                            'brand' => $item->brand,
                            'establishment' => [
                                'id' => $establishment->id,
                                'name' => $establishment->fantasy ?: $establishment->name,
                                'slug' => $establishment->slug,
                                'uf' => $establishment->uf,
                            ],
                        ]
                    ));
                }
            });

        DB::transaction(function () use ($rows) {
            DB::table('discovery_search_documents')->delete();
            $rows->chunk(500)->each(function (Collection $chunk) {
                if ($chunk->isNotEmpty()) {
                    DB::table('discovery_search_documents')->insert($chunk->all());
                }
            });
        });

        Cache::put('discovery-search-index-version', (string) Str::uuid(), now()->addDays(30));

        return $rows->count();
    }

    public function ensureBuilt(): void
    {
        if (! DB::table('discovery_search_documents')->exists()) {
            $this->rebuild();
        }
    }

    public function search(string $term, ?string $city = null, ?int $applicationId = null, int $limit = 8): array
    {
        $this->ensureBuilt();
        $normalized = $this->normalize($term);
        $tokens = collect(preg_split('/\s+/', $normalized))
            ->filter(fn ($token) => mb_strlen($token) >= 2)
            ->unique()
            ->values();
        $version = Cache::get('discovery-search-index-version', '1');
        $key = 'discovery-ranked:' . sha1(json_encode([$version, $normalized, $city, $applicationId, $limit]));

        return Cache::remember($key, now()->addMinutes(3), function () use ($normalized, $tokens, $city, $applicationId, $limit) {
            $query = $this->queryForApplication($applicationId);
            if ($city) {
                $query->where(fn ($scope) => $scope->whereNull('city')->orWhere('city', 'like', '%' . $city . '%'));
            }
            $query->where(function ($scope) use ($tokens, $normalized) {
                $scope->where('title', 'like', '%' . $normalized . '%')
                    ->orWhere('search_text', 'like', '%' . $normalized . '%');
                foreach ($tokens as $token) {
                    $scope->orWhere('title', 'like', '%' . $token . '%')
                        ->orWhere('search_text', 'like', '%' . $token . '%');
                }
            });

            $documents = $query->limit(250)->get()->map(function ($row) use ($normalized, $tokens, $city) {
                $title = $this->normalize((string) $row->title);
                $text = $this->normalize((string) $row->search_text);
                $score = (int) $row->boost;

                if ($title === $normalized) {
                    $score += 80;
                } elseif (str_starts_with($title, $normalized)) {
                    $score += 55;
                } elseif (str_contains($title, $normalized)) {
                    $score += 38;
                }

                if (str_contains($text, $normalized)) {
                    $score += 24;
                }

                foreach ($tokens as $token) {
                    if (str_contains($title, $token)) {
                        $score += 12;
                    } elseif (str_contains($text, $token)) {
                        $score += 5;
                    } else {
                        foreach (preg_split('/\s+/', $title) as $word) {
                            if (mb_strlen($word) > 3 && levenshtein($token, $word) <= 1) {
                                $score += 4;
                                break;
                            }
                        }
                    }
                }

                if ($city && $row->city && Str::contains(Str::lower($row->city), Str::lower($city))) {
                    $score += 18;
                }

                $row->score = $score;
                $row->metadata = json_decode($row->metadata ?: 'null', true);

                return $row;
            })->sortByDesc('score')->take($limit * 4)->values();

            return [
                'query' => $normalized,
                'count' => $documents->count(),
                'results' => $documents->groupBy('document_type')->map(
                    fn ($group) => $group->take($limit)->map(fn ($row) => $this->serialize($row))->values()
                )->all(),
            ];
        });
    }

    public function recommendations(
        Collection $terms,
        Collection $seenKeys,
        Collection $popularEntities,
        ?int $applicationId = null,
        int $limit = 8
    ): array {
        $this->ensureBuilt();
        $rows = collect();

        if ($terms->isNotEmpty()) {
            $query = $this->queryForApplication($applicationId);
            $query->where(function ($scope) use ($terms) {
                foreach ($terms as $term) {
                    $scope->orWhere('search_text', 'like', '%' . $term . '%')
                        ->orWhere('category', 'like', '%' . $term . '%');
                }
            });
            $rows = $query->orderByDesc('boost')->limit(max($limit * 8, 50))->get();
        } else {
            foreach ($popularEntities as $popular) {
                $type = $popular->entity_type === 'product' ? 'item' : $popular->entity_type;
                $row = $this->queryForApplication($applicationId)
                    ->where('document_type', $type)
                    ->where('document_id', $popular->entity_id)
                    ->orderByDesc('application_id')
                    ->first();
                if ($row) {
                    $rows->push($row);
                }
                if ($rows->count() >= $limit * 3) {
                    break;
                }
            }
        }

        return $rows
            ->reject(fn ($row) => $seenKeys->contains($row->document_type . ':' . $row->document_id))
            ->unique(fn ($row) => $row->document_type . ':' . $row->document_id . ':' . ($row->application_id ?? 'global'))
            ->take($limit)
            ->map(fn ($row) => $this->serialize($row, false))
            ->values()
            ->all();
    }

    private function queryForApplication(?int $applicationId)
    {
        $query = DB::table('discovery_search_documents');

        if ($applicationId) {
            $query->where(fn ($scope) => $scope
                ->whereNull('application_id')
                ->orWhere('application_id', $applicationId));
        }

        return $query;
    }

    private function document(
        string $type,
        int $id,
        ?int $applicationId,
        string $title,
        ?string $summary,
        ?string $category,
        ?string $city,
        string $url,
        int $boost,
        array $metadata
    ): array {
        $text = implode(' ', array_filter([
            $title,
            $summary,
            $category,
            $city,
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]));

        return [
            'application_id' => $applicationId,
            'document_type' => $type,
            'document_id' => $id,
            'title' => $title,
            'summary' => $summary,
            'search_text' => $this->normalize($text),
            'category' => $category,
            'city' => $city,
            'url' => $url,
            'boost' => $boost,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function serialize(object $row, bool $withScore = true): array
    {
        $payload = [
            'type' => $row->document_type,
            'id' => $row->document_id,
            'title' => $row->title,
            'description' => $row->summary,
            'category' => $row->category,
            'location' => $row->city,
            'url' => $row->url,
            'application_id' => $row->application_id,
            'metadata' => is_array($row->metadata ?? null)
                ? $row->metadata
                : json_decode($row->metadata ?: 'null', true),
        ];

        if ($withScore && isset($row->score)) {
            $payload['score'] = $row->score;
        }

        return $payload;
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
