<?php

namespace App\Domain\Discovery\Services;

use App\Models\Application;
use App\Models\Artist;
use App\Models\ContentEntry;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DiscoverySearchService
{
    public function __construct(private readonly DiscoveryService $discovery)
    {
    }

    public function search(string $term, ?string $city = null, int|string|null $application = null, int $limit = 8): array
    {
        $term = trim($term);
        $city = $city ? trim($city) : null;
        $like = '%' . $term . '%';
        $app = $this->discovery->resolveApplication($application);
        $publicApplication = fn (Builder $query) => $query->where('is_active', true)->where('is_visible', true);

        $applications = Application::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where(function (Builder $query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like);
            })
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'description', 'url', 'logo', 'category']);

        if ($app) {
            $applications = $applications->where('id', $app->id)->values();
        }

        $contents = ContentEntry::query()
            ->published()
            ->forApplication($app)
            ->where(function (Builder $query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('cluster', 'like', $like)
                    ->orWhere('search_intent', 'like', $like)
                    ->orWhere('content', 'like', $like);
            })
            ->latest('published_at')
            ->limit($limit)
            ->get(['id', 'application_id', 'title', 'slug', 'excerpt', 'category', 'cluster', 'search_intent', 'published_at']);

        $establishments = Establishment::query()
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->where(function (Builder $query) use ($publicApplication) {
                $query->whereHas('app', $publicApplication)
                    ->orWhereHas('applications', $publicApplication);
            })
            ->when($app, fn (Builder $query) => $query->forApplication($app->id))
            ->when($city, fn (Builder $query) => $query->where('city', 'like', '%' . $city . '%'))
            ->where(function (Builder $query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('fantasy', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhere('city', 'like', $like);
            })
            ->limit($limit)
            ->get(['id', 'app_id', 'name', 'fantasy', 'slug', 'description', 'category', 'type', 'city', 'uf']);

        $items = Item::query()
            ->with(['establishment:id,app_id,name,fantasy,slug,city,uf,is_published,is_cancelled'])
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->when($app, function (Builder $query) use ($app) {
                $query->where(function (Builder $apps) use ($app) {
                    $apps->where('app_id', $app->id)
                        ->orWhereHas('establishment', fn (Builder $est) => $est->forApplication($app->id));
                });
            })
            ->whereHas('establishment', function (Builder $query) use ($city, $publicApplication) {
                $query->where('is_cancelled', false)->where('is_published', true)
                    ->where(function (Builder $apps) use ($publicApplication) {
                        $apps->whereHas('app', $publicApplication)
                            ->orWhereHas('applications', $publicApplication);
                    });
                if ($city) $query->where('city', 'like', '%' . $city . '%');
            })
            ->where(function (Builder $query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('subcategory', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('type', 'like', $like);
            })
            ->limit($limit)
            ->get(['id', 'app_id', 'entity_id', 'slug', 'name', 'description', 'category', 'subcategory', 'brand', 'type', 'price', 'image']);

        $events = Event::query()
            ->with('application:id,name,slug,url,is_active,is_visible')
            ->whereHas('application', $publicApplication)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn (Builder $query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->where('end_date', '>', now())
            ->when($app, fn (Builder $query) => $query->where('app_id', $app->id))
            ->when($city, fn (Builder $query) => $query->where('city', 'like', '%' . $city . '%'))
            ->where(function (Builder $query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('venue', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('establishment_name', 'like', $like);
            })
            ->orderBy('start_date')
            ->limit($limit)
            ->get(['id', 'app_id', 'slug', 'title', 'description', 'category', 'image', 'venue', 'city', 'uf', 'event_format', 'start_date', 'end_date']);

        $artists = Artist::query()
            ->with('application:id,name,slug,url,is_active,is_visible')
            ->whereHas('application', $publicApplication)
            ->where('is_published', true)
            ->when($app, fn (Builder $query) => $query->where('app_id', $app->id))
            ->when($city, fn (Builder $query) => $query->where('city', 'like', '%' . $city . '%'))
            ->where(function (Builder $query) use ($like) {
                $query->where('stage_name', 'like', $like)
                    ->orWhere('bio', 'like', $like)
                    ->orWhere('artist_type', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('genres', 'like', $like);
            })
            ->orderBy('stage_name')
            ->limit($limit)
            ->get(['id', 'app_id', 'slug', 'stage_name', 'bio', 'artist_type', 'genres', 'photo', 'city', 'uf']);

        $results = [
            'applications' => $applications->map(fn ($row) => [
                'type' => 'application', 'id' => $row->id, 'title' => $row->name,
                'description' => $row->description, 'category' => $row->category,
                'image' => $row->logo, 'url' => '/plataformas/' . $row->slug,
                'application' => $row->slug,
                'metadata' => array_values(array_filter([
                    $row->category ? ['label' => 'Categoria', 'value' => $row->category] : null,
                    ['label' => 'Status', 'value' => 'Disponível'],
                ])),
            ])->values(),
            'content' => $contents->map(fn ($row) => [
                'type' => 'content', 'id' => $row->id, 'title' => $row->title,
                'description' => $row->excerpt, 'category' => $row->category,
                'cluster' => $row->cluster, 'url' => '/blog/' . $row->slug,
                'application_id' => $row->application_id,
                'metadata' => array_values(array_filter([
                    $row->category ? ['label' => 'Categoria', 'value' => $row->category] : null,
                    $row->cluster ? ['label' => 'Tema', 'value' => $row->cluster] : null,
                ])),
            ])->values(),
            'establishments' => $establishments->map(fn ($row) => [
                'type' => 'establishment', 'id' => $row->id,
                'title' => $row->fantasy ?: $row->name, 'description' => $row->description,
                'category' => $row->category ?: $row->type,
                'location' => trim(implode(' - ', array_filter([$row->city, $row->uf]))),
                'url' => '/empresas/' . $row->slug, 'application_id' => $row->app_id,
                'metadata' => array_values(array_filter([
                    ($row->category ?: $row->type) ? ['label' => 'Categoria', 'value' => $row->category ?: $row->type] : null,
                    $row->city ? ['label' => 'Local', 'value' => trim(implode(' - ', array_filter([$row->city, $row->uf])))] : null,
                ])),
            ])->values(),
            'items' => $items->map(fn ($row) => [
                'type' => 'item', 'id' => $row->id, 'title' => $row->name,
                'description' => $row->description, 'category' => $row->category ?: $row->type,
                'price' => $row->price, 'image' => $row->image_url ?? $row->image,
                'location' => $row->establishment ? trim(implode(' - ', array_filter([$row->establishment->city, $row->establishment->uf]))) : null,
                'establishment' => $row->establishment ? ['id' => $row->establishment->id, 'name' => $row->establishment->fantasy ?: $row->establishment->name, 'slug' => $row->establishment->slug] : null,
                'url' => '/solucoes/' . ($row->slug ?: $row->id), 'application_id' => $row->app_id,
                'metadata' => array_values(array_filter([
                    ($row->category ?: $row->type) ? ['label' => 'Categoria', 'value' => $row->category ?: $row->type] : null,
                    $row->brand ? ['label' => 'Marca', 'value' => $row->brand] : null,
                    $row->establishment ? ['label' => 'Empresa', 'value' => $row->establishment->fantasy ?: $row->establishment->name] : null,
                ])),
            ])->values(),
            'events' => $events->map(fn ($row) => [
                'type' => 'event', 'id' => $row->id, 'title' => $row->title,
                'description' => $row->description, 'category' => $row->category,
                'image' => $row->image, 'location' => trim(implode(' - ', array_filter([$row->venue, $row->city, $row->uf]))),
                'url' => $this->applicationUrl($row->application?->url, '/event/' . $row->slug),
                'application' => $row->application?->slug, 'application_name' => $row->application?->name,
                'starts_at' => $row->start_date?->toIso8601String(), 'ends_at' => $row->end_date?->toIso8601String(),
                'metadata' => array_values(array_filter([
                    $row->category ? ['label' => 'Categoria', 'value' => $row->category] : null,
                    $row->start_date ? ['label' => 'Quando', 'value' => $row->start_date->timezone(config('app.timezone'))->format('d/m/Y H:i')] : null,
                    $row->city ? ['label' => 'Local', 'value' => trim(implode(' - ', array_filter([$row->city, $row->uf])))] : null,
                ])),
            ])->values(),
            'artists' => $artists->map(fn ($row) => [
                'type' => 'artist', 'id' => $row->id, 'title' => $row->stage_name,
                'description' => $row->bio, 'category' => $row->artist_type,
                'image' => $row->photo, 'location' => trim(implode(' - ', array_filter([$row->city, $row->uf]))),
                'url' => $this->applicationUrl($row->application?->url, '/artist/' . $row->slug),
                'application' => $row->application?->slug, 'application_name' => $row->application?->name,
                'metadata' => array_values(array_filter([
                    $row->artist_type ? ['label' => 'Tipo', 'value' => $row->artist_type] : null,
                    $row->city ? ['label' => 'Local', 'value' => trim(implode(' - ', array_filter([$row->city, $row->uf])))] : null,
                    is_array($row->genres) && $row->genres ? ['label' => 'Gêneros', 'value' => implode(', ', array_slice($row->genres, 0, 3))] : null,
                ])),
            ])->values(),
        ];

        return [
            'query' => $term,
            'city' => $city,
            'application' => $app?->only(['id', 'name', 'slug']),
            'intent' => $this->intent($term, $applications, $contents, $establishments, $items),
            'group_labels' => [
                'applications' => 'Plataformas', 'content' => 'Conteúdos', 'establishments' => 'Empresas',
                'items' => 'Produtos e serviços', 'events' => 'Eventos', 'artists' => 'Artistas',
            ],
            'totals' => collect($results)->map(fn ($rows) => $rows->count())->all(),
            'results' => $results,
        ];
    }

    public function landing(string $term, ?string $city = null, int|string|null $application = null): array
    {
        $search = $this->search($term, $city, $application, 20);
        $uniqueEstablishments = collect($search['results']['items'])
            ->pluck('establishment.id')->filter()->merge(collect($search['results']['establishments'])->pluck('id'))->unique()->count();
        $entityTotal = array_sum($search['totals']);
        $indexable = $entityTotal >= 4 && ($uniqueEstablishments >= 2 || ($search['totals']['events'] ?? 0) >= 2 || ($search['totals']['content'] ?? 0) >= 2);
        $location = $city ? ' em ' . trim($city) : '';
        $title = Str::headline($term) . $location . ' | Peter Tecnet';

        return $search + [
            'indexable' => $indexable,
            'seo' => [
                'title' => Str::limit($title, 68, ''),
                'description' => Str::limit("Encontre " . Str::lower($term) . "{$location}: empresas, produtos, serviços, eventos, artistas, conteúdos e plataformas relacionados no ecossistema Peter Tecnet.", 158, ''),
                'canonical_path' => '/descobrir/' . Str::slug($term) . ($city ? '/' . Str::slug($city) : ''),
                'robots' => $indexable ? 'index, follow' : 'noindex, follow',
            ],
        ];
    }

    public function landingCandidates(int $limit = 120): Collection
    {
        $items = Item::query()
            ->with('establishment:id,name,fantasy,slug,city,uf,is_published,is_cancelled')
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereNotNull('category')
            ->whereHas('establishment', fn (Builder $query) => $query->where('is_cancelled', false)->where('is_published', true))
            ->limit(2500)
            ->get(['id', 'entity_id', 'category']);

        return $items->groupBy(function ($item) {
            $city = Str::slug((string) ($item->establishment?->city ?: ''));
            return Str::slug((string) $item->category) . '|' . $city;
        })->map(function (Collection $group) {
            $first = $group->first();
            return [
                'term' => $first->category,
                'city' => $first->establishment?->city,
                'count' => $group->count(),
                'establishments' => $group->pluck('entity_id')->filter()->unique()->count(),
            ];
        })->filter(fn ($row) => $row['count'] >= 4 && $row['establishments'] >= 2)
            ->sortByDesc('count')
            ->take($limit)
            ->values();
    }

    private function intent(string $term, Collection $applications, Collection $contents, Collection $establishments, Collection $items): array
    {
        $applicationScores = collect();
        foreach ($applications as $row) $applicationScores[$row->slug] = ($applicationScores[$row->slug] ?? 0) + 6;
        foreach ($contents as $row) if ($row->application_id) $applicationScores[(string) $row->application_id] = ($applicationScores[(string) $row->application_id] ?? 0) + 2;
        foreach ($establishments as $row) if ($row->app_id) $applicationScores[(string) $row->app_id] = ($applicationScores[(string) $row->app_id] ?? 0) + 2;
        foreach ($items as $row) if ($row->app_id) $applicationScores[(string) $row->app_id] = ($applicationScores[(string) $row->app_id] ?? 0) + 1;

        $dominant = collect([
            'application' => $applications->count() * 3,
            'content' => $contents->count() * 2,
            'establishment' => $establishments->count() * 2,
            'item' => $items->count() * 3,
        ])->sortDesc()->keys()->first();

        return [
            'query' => $term,
            'dominant_entity' => $dominant,
            'top_application' => $applicationScores->sortDesc()->keys()->first(),
            'confidence' => min(100, (int) round(($applications->count() + $contents->count() + $establishments->count() + $items->count()) * 7.5)),
        ];
    }

    private function applicationUrl(?string $base, string $path): string
    {
        if (! $base) return '/';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
