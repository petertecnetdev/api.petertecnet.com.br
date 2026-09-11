<?php

namespace App\Domain\Discovery\Services;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Item;
use App\Models\Production;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GlobalSearchService
{
    public function __construct(
        private readonly SearchRelevance $relevance,
        private readonly SearchCampaignService $campaigns,
        private readonly ExternalSearchIndexGateway $externalIndex,
    ) {}

    public function search(
        int $appId,
        ?User $viewer,
        array $parsed,
        string $type = 'all',
        int $limit = 8,
        int $page = 1
    ): array {
        $limit = max(1, min(30, $limit));
        $page = max(1, $page);
        $query = trim((string) ($parsed['search_text'] ?? $parsed['normalized'] ?? ''));
        $filters = (array) ($parsed['filters'] ?? []);
        $signals = $this->viewerSignals($appId, $viewer);

        $groups = [
            'people' => collect(),
            'events' => collect(),
            'productions' => collect(),
            'artists' => collect(),
            'posts' => collect(),
            'items' => collect(),
            'venues' => collect(),
            'promoters' => collect(),
        ];

        if ($type === 'all' || $type === 'event') {
            $groups['events'] = $this->events($appId, $viewer, $query, $parsed, $signals, $limit, $page);
        }
        if ($type === 'all' || $type === 'production') {
            $groups['productions'] = $this->productions($appId, $query, $parsed, $signals, $limit, $page, false);
        }
        if ($type === 'all' || $type === 'artist') {
            $groups['artists'] = $this->artists($appId, $query, $parsed, $signals, $limit, $page);
        }
        if ($type === 'all' || $type === 'user') {
            $groups['people'] = $this->people($appId, $viewer, $query, $parsed, $signals, $limit, $page, false);
        }
        if ($type === 'all' || $type === 'post') {
            $groups['posts'] = $this->posts($appId, $viewer, $query, $parsed, $signals, $limit, $page);
        }
        if ($type === 'all' || $type === 'item') {
            $groups['items'] = $this->items($appId, $query, $parsed, $signals, $limit, $page);
        }
        if ($type === 'venue') {
            $groups['venues'] = $this->productions($appId, $query, $parsed, $signals, $limit, $page, true);
        }
        if ($type === 'promoter') {
            $groups['promoters'] = $this->people($appId, $viewer, $query, $parsed, $signals, $limit, $page, true);
        }

        if ($type === 'all' && $limit <= 10) {
            $groups['posts'] = $groups['posts']->take(5)->values();
            $groups['items'] = $groups['items']->take(5)->values();
        }

        $sponsored = $this->campaigns->active(
            $appId,
            (string) ($parsed['normalized'] ?? ''),
            $filters['city'] ?? null,
            $filters['uf'] ?? null,
            3
        );

        $results = collect()
            ->merge($sponsored)
            ->merge($groups['people'])
            ->merge($groups['events'])
            ->merge($groups['productions'])
            ->merge($groups['artists'])
            ->merge($groups['posts'])
            ->merge($groups['items'])
            ->merge($groups['venues'])
            ->merge($groups['promoters'])
            ->unique(fn ($item) => ($item['type'] ?? '').':'.($item['id'] ?? ''))
            ->values();

        $counts = [
            'people' => $groups['people']->count(),
            'events' => $groups['events']->count(),
            'productions' => $groups['productions']->count(),
            'artists' => $groups['artists']->count(),
            'posts' => $groups['posts']->count(),
            'items' => $groups['items']->count(),
            'venues' => $groups['venues']->count(),
            'promoters' => $groups['promoters']->count(),
            'sponsored' => $sponsored->count(),
            'total' => $results->count(),
        ];

        return [
            'query' => $parsed['raw'] ?? '',
            'normalized_query' => $parsed['normalized'] ?? '',
            'type' => $type,
            'page' => $page,
            'has_more' => $type !== 'all' && $this->hasMore($groups, $type, $limit),
            'next_cursor' => $type !== 'all' && $this->hasMore($groups, $type, $limit) ? $this->encodeCursor($page + 1) : null,
            'results' => $results,
            'groups' => [
                'people' => $groups['people']->values(),
                'events' => $groups['events']->values(),
                'productions' => $groups['productions']->values(),
                'artists' => $groups['artists']->values(),
                'posts' => $groups['posts']->values(),
                'items' => $groups['items']->values(),
                'venues' => $groups['venues']->values(),
                'promoters' => $groups['promoters']->values(),
            ],
            'sponsored' => $sponsored,
            'counts' => $counts,
            'filters' => $filters,
            'did_you_mean' => $counts['total'] === 0 ? $this->didYouMean($appId, (string) ($parsed['normalized'] ?? '')) : null,
        ];
    }

    public function suggestions(int $appId, ?User $viewer, array $parsed, int $limit = 10): array
    {
        $query = trim((string) ($parsed['search_text'] ?? $parsed['normalized'] ?? ''));
        $normalized = (string) ($parsed['normalized'] ?? '');
        $signals = $this->viewerSignals($appId, $viewer);
        $limit = max(3, min(15, $limit));

        $people = $this->people($appId, $viewer, $query, $parsed, $signals, 4, 1, false);
        $events = $this->events($appId, $viewer, $query, $parsed, $signals, 4, 1);
        $productions = $this->productions($appId, $query, $parsed, $signals, 3, 1, false);
        $artists = $this->artists($appId, $query, $parsed, $signals, 3, 1);

        $entities = collect()
            ->merge($people)
            ->merge($events)
            ->merge($productions)
            ->merge($artists)
            ->sortByDesc('score')
            ->unique(fn ($item) => $item['identity_key'] ?? (($item['type'] ?? '').':'.($item['id'] ?? '')))
            ->take($limit)
            ->values();

        $terms = $this->popularTerms($appId, 30, null, $limit * 2)
            ->filter(fn ($row) => $normalized === '' || str_contains((string) $row->normalized_query, $normalized) || $this->similar($normalized, (string) $row->normalized_query))
            ->take($limit)
            ->map(fn ($row) => [
                'query' => $row->query,
                'normalized_query' => $row->normalized_query,
                'searches' => (int) $row->searches,
            ])
            ->values();

        return [
            'entities' => $entities,
            'terms' => $terms,
            'did_you_mean' => $this->didYouMean($appId, $normalized),
        ];
    }

    public function discover(int $appId, ?User $viewer, array $input = []): array
    {
        $city = trim((string) ($input['city'] ?? ''));
        $uf = strtoupper(trim((string) ($input['uf'] ?? '')));
        $lat = isset($input['lat']) ? (float) $input['lat'] : null;
        $lng = isset($input['lng']) ? (float) $input['lng'] : null;
        $signals = $this->viewerSignals($appId, $viewer);

        if ($viewer && $city === '' && Schema::hasTable('application_user_preferences')) {
            $prefs = DB::table('application_user_preferences')
                ->where('app_id', $appId)
                ->where('user_id', $viewer->id)
                ->first();
            $city = trim((string) ($prefs->preferred_city ?? ''));
            $uf = strtoupper(trim((string) ($prefs->preferred_uf ?? '')));
            $lat ??= isset($prefs->latitude) ? (float) $prefs->latitude : null;
            $lng ??= isset($prefs->longitude) ? (float) $prefs->longitude : null;
        }

        $parsed = [
            'raw' => '',
            'normalized' => '',
            'search_text' => '',
            'terms' => [],
            'expanded_terms' => [],
            'hashtags' => [],
            'filters' => [
                'city' => $city ?: null,
                'uf' => $uf ?: null,
                'lat' => $lat,
                'lng' => $lng,
                'radius_km' => 50,
                'sort' => 'popular',
            ],
        ];

        $trendingTerms = $this->popularTerms($appId, 7, $city ?: null, 12);

        $events = $this->events($appId, $viewer, '', $parsed, $signals, 12, 1);
        $productions = $this->productions($appId, '', $parsed, $signals, 10, 1, false);
        $artists = $this->artists($appId, '', $parsed, $signals, 10, 1);

        $nearby = collect();
        if ($lat !== null && $lng !== null) {
            $nearby = $events
                ->filter(fn ($item) => isset($item['meta']['distance_km']))
                ->sortBy(fn ($item) => $item['meta']['distance_km'])
                ->take(10)
                ->values();
        }

        $newEvents = Event::query()
            ->where('app_id', $appId)
            ->publiclyVisible()
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', now()))
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Event $event) => $this->eventPayload($event, $viewer, $signals, '', null))
            ->filter()
            ->values();

        return [
            'trending_terms' => $trendingTerms,
            'for_you' => $events->take(10)->values(),
            'trending_events' => $events->sortByDesc('score')->take(10)->values(),
            'nearby' => $nearby,
            'new_events' => $newEvents,
            'productions' => $productions->take(8)->values(),
            'artists' => $artists->take(8)->values(),
            'context' => ['city' => $city ?: null, 'uf' => $uf ?: null],
        ];
    }

    public function popularTerms(int $appId, int $days = 7, ?string $city = null, int $limit = 20): Collection
    {
        if (! Schema::hasTable('search_queries')) {
            return collect();
        }

        $boundedDays = max(1, min(90, $days));
        $boundedLimit = max(1, min(100, $limit));
        $cacheKey = 'search:popular:'.$appId.':'.$boundedDays.':'.sha1(mb_strtolower((string) $city)).':'.$boundedLimit;

        return Cache::remember($cacheKey, now()->addSeconds(60), fn () => DB::table('search_queries')
            ->where('app_id', $appId)
            ->where('created_at', '>=', now()->subDays($boundedDays))
            ->where('normalized_query', '<>', '')
            ->when($city, fn ($q) => $q->whereRaw('LOWER(city) = LOWER(?)', [$city]))
            ->selectRaw('normalized_query, MAX(query) as query, COUNT(*) as searches, SUM(zero_result) as zero_results')
            ->groupBy('normalized_query')
            ->orderByDesc('searches')
            ->limit($boundedLimit)
            ->get());
    }

    private function events(
        int $appId,
        ?User $viewer,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page
    ): Collection {
        $filters = (array) ($parsed['filters'] ?? []);
        $expanded = collect($parsed['expanded_terms'] ?? [])->filter()->take(8)->values();
        $now = Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        $offset = ($page - 1) * $limit;
        $candidateLimit = max($limit * 6, 80);

        $builder = Event::query()
            ->where('events.app_id', $appId)
            ->publiclyVisible()
            ->with([
                'production:id,name,slug,logo',
                'artists:id,stage_name,slug,photo',
            ]);

        $this->applyEventFilters($builder, $filters, $now);

        $externalEventIds = $query !== '' ? $this->externalIndex->candidateIds('event', $query, $filters, $candidateLimit) : null;
        if ($externalEventIds?->isNotEmpty()) {
            $builder->whereIn('events.id', $externalEventIds->all());
        } elseif ($query !== '') {
            $terms = collect(array_merge([$query], $expanded->all()))->filter()->unique()->take(8);
            $builder->where(function (Builder $where) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $where->orWhere('events.title', 'like', $like)
                        ->orWhere('events.description', 'like', $like)
                        ->orWhere('events.city', 'like', $like)
                        ->orWhere('events.venue', 'like', $like)
                        ->orWhere('events.address', 'like', $like)
                        ->orWhere('events.neighborhood', 'like', $like)
                        ->orWhere('events.formatted_address', 'like', $like)
                        ->orWhere('events.establishment_name', 'like', $like)
                        ->orWhere('events.organizer_name', 'like', $like)
                        ->orWhere('events.agenda', 'like', $like)
                        ->orWhere('events.additional_info', 'like', $like)
                        ->orWhere('events.category', 'like', $like)
                        ->orWhereHas('production', fn ($p) => $p->where('name', 'like', $like))
                        ->orWhereHas('artists', fn ($a) => $a->where('stage_name', 'like', $like));
                }
            });
        }

        $candidates = $builder
            ->orderByRaw('CASE WHEN events.end_date >= ? THEN 0 ELSE 1 END', [$now])
            ->orderByDesc('events.is_featured')
            ->orderBy('events.start_date')
            ->limit($candidateLimit)
            ->get();

        if ($query !== '' && $candidates->count() < min(10, $limit)) {
            $fallbackBuilder = Event::query()
                ->where('events.app_id', $appId)
                ->publiclyVisible()
                ->with(['production:id,name,slug,logo', 'artists:id,stage_name,slug,photo'])
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $now->copy()->subDays(7)));
            $this->applyEventFilters($fallbackBuilder, $filters, $now);
            $fallback = $fallbackBuilder
                ->orderByDesc('is_featured')
                ->orderByDesc('created_at')
                ->limit(120)
                ->get();
            $candidates = $candidates->merge($fallback)->unique('id')->values();
        }

        $eventIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $passCounts = $eventIds
            ? DB::table('event_passes')->whereIn('event_id', $eventIds)->selectRaw('event_id, COUNT(*) total')->groupBy('event_id')->pluck('total', 'event_id')
            : collect();
        $recentPassCounts = $eventIds
            ? DB::table('event_passes')->whereIn('event_id', $eventIds)->where('created_at', '>=', now()->subDays(7))->selectRaw('event_id, COUNT(*) total')->groupBy('event_id')->pluck('total', 'event_id')
            : collect();
        $searchPerformance = $this->performanceSignals($appId, 'event', $eventIds);
        $viewerPasses = $viewer && $eventIds
            ? DB::table('event_passes')->where('user_id', $viewer->id)->whereIn('event_id', $eventIds)->pluck('event_id')->map(fn ($id) => (int) $id)->flip()
            : collect();

        $ranked = $candidates
            ->map(fn (Event $event) => $this->eventPayload($event, $viewer, $viewerSignals, $query, [
                'passes' => (int) ($passCounts[$event->id] ?? 0),
                'recent_passes' => (int) ($recentPassCounts[$event->id] ?? 0),
                'search_performance' => (float) ($searchPerformance[$event->id] ?? 0),
                'purchased' => $viewerPasses->has((int) $event->id),
                'filters' => $filters,
                'now' => $now,
            ]))
            ->filter(fn ($item) => $query === '' || ($item['score'] ?? 0) >= 28);

        return $this->sortResults($ranked, $filters)
            ->slice($offset, $limit)
            ->values();
    }

    private function productions(
        int $appId,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page,
        bool $venuesOnly
    ): Collection {
        $filters = (array) ($parsed['filters'] ?? []);
        $expanded = collect($parsed['expanded_terms'] ?? [])->filter()->take(8)->values();
        $offset = ($page - 1) * $limit;

        $builder = Production::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where(fn (Builder $q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'));

        if ($venuesOnly) {
            $builder->where(fn (Builder $q) => $q
                ->whereNull('category')
                ->orWhere('category', '<>', 'production')
                ->orWhere('type', '<>', 'production'));
        }

        if (! empty($filters['city'])) {
            $builder->whereRaw('LOWER(city) = LOWER(?)', [trim($filters['city'])]);
        }
        if (! empty($filters['uf'])) {
            $builder->where('uf', strtoupper($filters['uf']));
        }
        if (! empty($filters['genre'])) {
            $builder->where('genres', 'like', '%'.trim($filters['genre']).'%');
        }

        $externalProductionIds = $query !== '' ? $this->externalIndex->candidateIds($venuesOnly ? 'venue' : 'production', $query, $filters, max(80, $limit * 6)) : null;
        if ($externalProductionIds?->isNotEmpty()) {
            $builder->whereIn('id', $externalProductionIds->all());
        } elseif ($query !== '') {
            $terms = collect(array_merge([$query], $expanded->all()))->filter()->unique()->take(8);
            $builder->where(function (Builder $where) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $where->orWhere('name', 'like', $like)
                        ->orWhere('fantasy', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('category', 'like', $like);
                }
            });
        }

        $candidates = $builder
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit(max(80, $limit * 6))
            ->get();

        if ($query !== '' && $candidates->count() < min(10, $limit)) {
            $fallbackBuilder = Production::query()
                ->where('app_id', $appId)
                ->where('is_published', true)
                ->where(fn (Builder $q) => $q->where('is_cancelled', false)->orWhereNull('is_cancelled'));
            if (! empty($filters['city'])) $fallbackBuilder->whereRaw('LOWER(city) = LOWER(?)', [trim($filters['city'])]);
            if (! empty($filters['uf'])) $fallbackBuilder->where('uf', strtoupper($filters['uf']));
            $fallback = $fallbackBuilder
                ->orderByDesc('is_featured')
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get();
            $candidates = $candidates->merge($fallback)->unique('id')->values();
        }

        $ids = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $followers = $ids && Schema::hasTable('follows')
            ? DB::table('follows')->where('app_id', $appId)->where('target_type', 'production')->whereIn('target_id', $ids)->selectRaw('target_id, COUNT(*) total')->groupBy('target_id')->pluck('total', 'target_id')
            : collect();
        $eventCounts = $ids
            ? Event::query()->where('app_id', $appId)->publiclyVisible()->whereIn('production_id', $ids)->where('end_date', '>', now())->selectRaw('production_id, COUNT(*) total')->groupBy('production_id')->pluck('total', 'production_id')
            : collect();
        $searchPerformance = $this->performanceSignals($appId, $venuesOnly ? 'venue' : 'production', $ids);

        $ranked = $candidates
            ->map(function (Production $production) use ($query, $viewerSignals, $followers, $eventCounts, $filters, $venuesOnly, $searchPerformance) {
                $distance = $this->distanceFor($production->latitude, $production->longitude, $filters);
                $followed = isset($viewerSignals['followed']['production'][(int) $production->id]);
                $subtitle = collect([
                    $production->fantasy && $production->fantasy !== $production->name ? $production->fantasy : null,
                    trim(implode(' - ', array_filter([$production->city, $production->uf]))),
                ])->filter()->implode(' · ');
                $score = $this->relevance->score($query, (string) $production->name, $subtitle, [
                    (string) $production->description,
                    (string) $production->category,
                ], [
                    'followers' => (int) ($followers[$production->id] ?? 0),
                    'popularity' => (int) ($eventCounts[$production->id] ?? 0) + (float) ($searchPerformance[$production->id] ?? 0),
                    'followed' => $followed,
                    'featured' => (bool) $production->is_featured,
                    'distance_km' => $distance,
                ]);

                return [
                    'type' => $venuesOnly ? 'venue' : 'production',
                    'id' => (int) $production->id,
                    'title' => $production->name,
                    'subtitle' => $subtitle,
                    'image' => $production->logo,
                    'url' => '/production/'.$production->slug.'/public',
                    'score' => $score,
                    'badges' => array_values(array_filter([
                        $followed ? 'Você segue' : null,
                        ($eventCounts[$production->id] ?? 0) > 0 ? ($eventCounts[$production->id].' próximos eventos') : null,
                    ])),
                    'meta' => [
                        'city' => $production->city,
                        'uf' => $production->uf,
                        'followers_count' => (int) ($followers[$production->id] ?? 0),
                        'upcoming_events_count' => (int) ($eventCounts[$production->id] ?? 0),
                        'distance_km' => $distance,
                        'is_following' => $followed,
                        'search_performance' => (float) ($searchPerformance[$production->id] ?? 0),
                        'updated_at' => optional($production->updated_at)->toIso8601String(),
                    ],
                ];
            })
            ->filter(fn ($item) => $query === '' || ($item['score'] ?? 0) >= 28);

        return $this->sortResults($ranked, $filters)
            ->slice($offset, $limit)
            ->values();
    }

    private function artists(
        int $appId,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page
    ): Collection {
        $filters = (array) ($parsed['filters'] ?? []);
        $expanded = collect($parsed['expanded_terms'] ?? [])->filter()->take(8)->values();
        $offset = ($page - 1) * $limit;

        $builder = Artist::query()->where('app_id', $appId)->where('is_published', true);

        if (! empty($filters['city'])) {
            $builder->whereRaw('LOWER(city) = LOWER(?)', [trim($filters['city'])]);
        }
        if (! empty($filters['uf'])) {
            $builder->where('uf', strtoupper($filters['uf']));
        }

        $externalArtistIds = $query !== '' ? $this->externalIndex->candidateIds('artist', $query, $filters, max(80, $limit * 6)) : null;
        if ($externalArtistIds?->isNotEmpty()) {
            $builder->whereIn('id', $externalArtistIds->all());
        } elseif ($query !== '') {
            $terms = collect(array_merge([$query], $expanded->all()))->filter()->unique()->take(8);
            $builder->where(function (Builder $where) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $where->orWhere('stage_name', 'like', $like)
                        ->orWhere('bio', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('genres', 'like', $like);
                }
            });
        }

        $candidates = $builder->orderBy('stage_name')->limit(max(80, $limit * 6))->get();

        if ($query !== '' && $candidates->count() < min(10, $limit)) {
            $fallbackBuilder = Artist::query()->where('app_id', $appId)->where('is_published', true);
            if (! empty($filters['city'])) $fallbackBuilder->whereRaw('LOWER(city) = LOWER(?)', [trim($filters['city'])]);
            if (! empty($filters['uf'])) $fallbackBuilder->where('uf', strtoupper($filters['uf']));
            if (! empty($filters['genre'])) $fallbackBuilder->where('genres', 'like', '%'.trim($filters['genre']).'%');
            $candidates = $candidates->merge(
                $fallbackBuilder->latest('updated_at')->limit(100)->get()
            )->unique('id')->values();
        }

        $ids = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $followers = $ids && Schema::hasTable('follows')
            ? DB::table('follows')->where('app_id', $appId)->where('target_type', 'artist')->whereIn('target_id', $ids)->selectRaw('target_id, COUNT(*) total')->groupBy('target_id')->pluck('total', 'target_id')
            : collect();
        $searchPerformance = $this->performanceSignals($appId, 'artist', $ids);

        $ranked = $candidates
            ->map(function (Artist $artist) use ($query, $viewerSignals, $followers, $searchPerformance) {
                $followed = isset($viewerSignals['followed']['artist'][(int) $artist->id]);
                $genres = is_array($artist->genres) ? $artist->genres : [];
                $subtitle = collect([
                    $this->artistTypeLabel($artist->artist_type),
                    $genres ? implode(' · ', array_slice($genres, 0, 2)) : null,
                    trim(implode(' - ', array_filter([$artist->city, $artist->uf]))),
                ])->filter()->take(2)->implode(' · ');
                $score = $this->relevance->score($query, (string) $artist->stage_name, $subtitle, [
                    (string) $artist->bio,
                    implode(' ', $genres),
                ], [
                    'followers' => (int) ($followers[$artist->id] ?? 0),
                    'popularity' => (float) ($searchPerformance[$artist->id] ?? 0),
                    'followed' => $followed,
                ]);

                return [
                    'type' => 'artist',
                    'id' => (int) $artist->id,
                    'identity_key' => $artist->user_id ? 'user:'.(int) $artist->user_id : 'artist:'.(int) $artist->id,
                    'title' => $artist->stage_name,
                    'subtitle' => $subtitle,
                    'image' => $artist->photo,
                    'url' => '/artist/'.$artist->slug,
                    'score' => $score,
                    'badges' => array_values(array_filter([$followed ? 'Você segue' : null])),
                    'meta' => [
                        'artist_type' => $artist->artist_type,
                        'genres' => $genres,
                        'followers_count' => (int) ($followers[$artist->id] ?? 0),
                        'is_following' => $followed,
                        'related_user_id' => $artist->user_id ? (int) $artist->user_id : null,
                        'search_performance' => (float) ($searchPerformance[$artist->id] ?? 0),
                        'updated_at' => optional($artist->updated_at)->toIso8601String(),
                    ],
                ];
            })
            ->filter(fn ($item) => $query === '' || ($item['score'] ?? 0) >= 28);

        return $this->sortResults($ranked, $filters)
            ->slice($offset, $limit)
            ->values();
    }

    private function people(
        int $appId,
        ?User $viewer,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page,
        bool $promotersOnly
    ): Collection {
        $personQuery = ltrim(trim($query), '@');
        $offset = ($page - 1) * $limit;
        $blocked = $viewerSignals['blocked'] ?? [];

        $builder = User::query()
            ->whereHas('applications', fn (Builder $application) => $application
                ->where('applications.id', $appId)
                ->where('application_user.status', 'active'))
            ->whereNotNull('email_verified_at')
            ->when(Schema::hasTable('user_social_preferences'), fn (Builder $q) => $q->whereNotExists(function ($privacy) use ($appId) {
                $privacy->selectRaw('1')
                    ->from('user_social_preferences')
                    ->whereColumn('user_social_preferences.user_id', 'users.id')
                    ->where('user_social_preferences.app_id', $appId)
                    ->where('user_social_preferences.discoverable', false);
            }))
            ->when($blocked, fn (Builder $q) => $q->whereNotIn('users.id', $blocked))
            ->when($promotersOnly, fn (Builder $q) => $q->where('is_promoter', true));

        $externalUserIds = $personQuery !== '' ? $this->externalIndex->candidateIds('user', $personQuery, (array) ($parsed['filters'] ?? []), max(80, $limit * 6)) : null;
        if ($externalUserIds?->isNotEmpty()) {
            $builder->whereIn('users.id', $externalUserIds->all());
        } elseif ($personQuery !== '') {
            $like = '%'.$personQuery.'%';
            $builder->where(function (Builder $where) use ($like) {
                $where->where('user_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", [$like]);
            });
        }

        $candidates = $builder->orderBy('first_name')->limit(max(80, $limit * 6))->get();

        if ($personQuery !== '' && $candidates->count() < min(10, $limit)) {
            $fallback = User::query()
                ->whereHas('applications', fn (Builder $application) => $application
                    ->where('applications.id', $appId)
                    ->where('application_user.status', 'active'))
                ->whereNotNull('email_verified_at')
                ->when($blocked, fn (Builder $q) => $q->whereNotIn('users.id', $blocked))
                ->when($promotersOnly, fn (Builder $q) => $q->where('is_promoter', true))
                ->latest('updated_at')
                ->limit(100)
                ->get();
            $candidates = $candidates->merge($fallback)->unique('id')->values();
        }

        $ids = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $followers = $ids && Schema::hasTable('follows')
            ? DB::table('follows')->where('app_id', $appId)->where('target_type', 'user')->whereIn('target_id', $ids)->selectRaw('target_id, COUNT(*) total')->groupBy('target_id')->pluck('total', 'target_id')
            : collect();
        $privacy = $ids && Schema::hasTable('user_social_preferences')
            ? DB::table('user_social_preferences')->where('app_id', $appId)->whereIn('user_id', $ids)->get()->keyBy('user_id')
            : collect();
        $artistByUser = $ids
            ? Artist::query()->where('app_id', $appId)->where('is_published', true)->whereIn('user_id', $ids)->get(['id','user_id','slug','stage_name'])->keyBy('user_id')
            : collect();
        $searchPerformance = $this->performanceSignals($appId, $promotersOnly ? 'promoter' : 'user', $ids);

        $ranked = $candidates
            ->map(function (User $user) use ($personQuery, $viewerSignals, $followers, $promotersOnly, $privacy, $artistByUser, $searchPerformance) {
                $name = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));
                $title = $name !== '' ? $name : ($user->user_name ?: 'Usuário Cutinapp');
                $social = $privacy->get((int) $user->id);
                $showCity = ! $social || (bool) ($social->show_city ?? true);
                $relatedArtist = $artistByUser->get((int) $user->id);
                $subtitle = collect([
                    $user->user_name ? '@'.ltrim($user->user_name, '@') : null,
                    $showCity ? trim(implode(' - ', array_filter([$user->city, $user->uf]))) : null,
                ])->filter()->implode(' · ');
                $followed = isset($viewerSignals['followed']['user'][(int) $user->id]);
                $score = $this->relevance->score($personQuery, $title, $subtitle, [
                    (string) $user->user_name,
                    (string) $user->occupation,
                    (string) $user->about,
                ], [
                    'followers' => (int) ($followers[$user->id] ?? 0),
                    'popularity' => (float) ($searchPerformance[$user->id] ?? 0),
                    'followed' => $followed,
                ]);

                return [
                    'type' => $promotersOnly ? 'promoter' : 'user',
                    'id' => (int) $user->id,
                    'identity_key' => 'user:'.(int) $user->id,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'image' => $user->avatar,
                    'url' => '/profile/'.$user->id,
                    'score' => $score,
                    'badges' => array_values(array_filter([
                        $followed ? 'Você segue' : null,
                        $promotersOnly ? 'Promoter' : null,
                        $relatedArtist ? 'Artista' : null,
                    ])),
                    'meta' => [
                        'user_name' => $user->user_name,
                        'followers_count' => (int) ($followers[$user->id] ?? 0),
                        'is_following' => $followed,
                        'is_promoter' => (bool) $user->is_promoter,
                        'related_artist' => $relatedArtist ? ['id' => (int) $relatedArtist->id, 'slug' => $relatedArtist->slug, 'stage_name' => $relatedArtist->stage_name] : null,
                        'search_performance' => (float) ($searchPerformance[$user->id] ?? 0),
                        'updated_at' => optional($user->updated_at)->toIso8601String(),
                    ],
                ];
            })
            ->filter(fn ($item) => $personQuery === '' || ($item['score'] ?? 0) >= 28);

        return $this->sortResults($ranked, $filters)
            ->slice($offset, $limit)
            ->values();
    }

    private function posts(
        int $appId,
        ?User $viewer,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page
    ): Collection {
        if (! Schema::hasTable('event_posts') || $query === '') {
            return collect();
        }

        $offset = ($page - 1) * $limit;
        $terms = collect(array_merge([$query], $parsed['expanded_terms'] ?? []))->filter()->unique()->take(8);
        $blocked = $viewerSignals['blocked'] ?? [];

        $builder = DB::table('event_posts as posts')
            ->join('events', 'events.id', '=', 'posts.event_id')
            ->leftJoin('users', 'users.id', '=', 'posts.user_id')
            ->where('posts.app_id', $appId)
            ->where('posts.status', 'published')
            ->where('events.app_id', $appId)
            ->where('events.is_published', true)
            ->where(fn ($q) => $q->where('events.is_private', false)->orWhereNull('events.is_private'))
            ->where(fn ($q) => $q->where('events.is_cancelled', false)->orWhereNull('events.is_cancelled'))
            ->when($blocked, fn ($q) => $q->whereNotIn('posts.user_id', $blocked))
            ->where(function ($where) use ($terms) {
                foreach ($terms as $term) {
                    $where->orWhere('posts.body', 'like', '%'.$term.'%')
                        ->orWhere('events.title', 'like', '%'.$term.'%');
                }
            })
            ->select([
                'posts.id',
                'posts.body',
                'posts.created_at',
                'events.id as event_id',
                'events.title as event_title',
                'events.slug as event_slug',
                'events.image as event_image',
                'users.id as user_id',
                'users.user_name',
                'users.first_name',
                'users.last_name',
            ])
            ->orderByDesc('posts.created_at')
            ->limit(max(60, $limit * 5))
            ->get();

        return $builder
            ->map(function ($post) use ($query) {
                $author = trim(implode(' ', array_filter([$post->first_name, $post->last_name])));
                $score = $this->relevance->score($query, (string) $post->body, (string) $post->event_title, [
                    $author,
                    (string) $post->user_name,
                ], ['upcoming' => false]);

                return [
                    'type' => 'post',
                    'id' => (int) $post->id,
                    'title' => mb_strimwidth((string) $post->body, 0, 90, '…'),
                    'subtitle' => trim(($author ?: ($post->user_name ?: 'Participante')).' · '.$post->event_title),
                    'image' => $post->event_image,
                    'url' => '/event/'.$post->event_slug.'#post-'.$post->id,
                    'score' => $score,
                    'badges' => ['Publicação'],
                    'meta' => ['event_id' => (int) $post->event_id, 'created_at' => $post->created_at],
                ];
            })
            ->filter(fn ($item) => ($item['score'] ?? 0) >= 28)
            ->sortByDesc('score')
            ->slice($offset, $limit)
            ->values();
    }

    private function items(
        int $appId,
        string $query,
        array $parsed,
        array $viewerSignals,
        int $limit,
        int $page
    ): Collection {
        if ($query === '') {
            return collect();
        }

        $offset = ($page - 1) * $limit;
        $terms = collect(array_merge([$query], $parsed['expanded_terms'] ?? []))->filter()->unique()->take(8);

        $builder = Item::query()
            ->forApplication($appId)
            ->active()
            ->with('establishment:id,name,slug,logo,city,uf');

        $externalItemIds = $this->externalIndex->candidateIds('item', $query, (array) ($parsed['filters'] ?? []), max(80, $limit * 6));
        if ($externalItemIds?->isNotEmpty()) {
            $builder->whereIn('items.id', $externalItemIds->all());
        } else {
            $builder->where(function (Builder $where) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.$term.'%';
                    $where->orWhere('items.name', 'like', $like)
                    ->orWhere('items.description', 'like', $like)
                    ->orWhere('items.category', 'like', $like)
                    ->orWhere('items.subcategory', 'like', $like)
                    ->orWhere('items.brand', 'like', $like)
                    ->orWhere('items.tags', 'like', $like);
                }
            });
        }

        $items = $builder->orderByDesc('is_featured')->latest('updated_at')->limit(max(80, $limit * 6))->get();

        return $items
            ->map(function (Item $item) use ($query) {
                $entity = $item->establishment;
                $subtitle = collect([
                    $entity?->name,
                    $item->category,
                    $item->price !== null ? 'R$ '.number_format((float) $item->price, 2, ',', '.') : null,
                ])->filter()->implode(' · ');
                $score = $this->relevance->score($query, (string) $item->name, $subtitle, [
                    (string) $item->description,
                    (string) $item->subcategory,
                    (string) $item->brand,
                    implode(' ', (array) $item->tags),
                ], ['featured' => (bool) $item->is_featured]);

                return [
                    'type' => 'item',
                    'id' => (int) $item->id,
                    'title' => $item->name,
                    'subtitle' => $subtitle,
                    'image' => $item->image_url,
                    'url' => $entity?->slug ? '/production/'.$entity->slug.'/public' : '/search?q='.urlencode($item->name),
                    'score' => $score,
                    'badges' => array_values(array_filter([
                        (float) $item->price <= 0 ? 'Grátis' : null,
                        $item->is_featured ? 'Destaque' : null,
                    ])),
                    'meta' => [
                        'price' => $item->price !== null ? (float) $item->price : null,
                        'category' => $item->category,
                        'production_id' => $entity?->id,
                    ],
                ];
            })
            ->filter(fn ($item) => ($item['score'] ?? 0) >= 28)
            ->sortByDesc('score')
            ->slice($offset, $limit)
            ->values();
    }

    private function eventPayload(Event $event, ?User $viewer, array $viewerSignals, string $query, ?array $context): ?array
    {
        $context ??= ['passes' => 0, 'purchased' => false, 'filters' => [], 'now' => now()];
        $now = $context['now'] instanceof Carbon ? $context['now'] : Carbon::parse($context['now']);
        $filters = (array) ($context['filters'] ?? []);
        $distance = $this->distanceFor($event->latitude, $event->longitude, $filters);
        $start = $event->start_date ? Carbon::parse($event->start_date) : null;
        $end = $event->end_date ? Carbon::parse($event->end_date) : null;
        $happeningNow = $start && $end && $now->between($start, $end);
        $upcoming = $start && $start->isFuture();
        $hoursUntil = $upcoming ? max(0, $now->diffInHours($start, false)) : null;
        $artistNames = $event->relationLoaded('artists') ? $event->artists->pluck('stage_name')->all() : [];
        $subtitle = collect([
            $event->production?->name,
            $event->venue,
            trim(implode(' - ', array_filter([$event->city, $event->uf]))),
        ])->filter()->take(2)->implode(' · ');
        $score = $this->relevance->score($query, (string) $event->title, $subtitle, array_merge([
            (string) $event->description,
            (string) $event->category,
            (string) $event->venue,
            (string) $event->address,
            (string) $event->neighborhood,
            (string) $event->formatted_address,
            (string) $event->establishment_name,
            (string) $event->organizer_name,
            json_encode($event->agenda, JSON_UNESCAPED_UNICODE) ?: '',
            json_encode($event->additional_info, JSON_UNESCAPED_UNICODE) ?: '',
        ], $artistNames), [
            'sales' => (int) ($context['passes'] ?? 0) + ((int) ($context['recent_passes'] ?? 0) * 3) + (float) ($context['search_performance'] ?? 0),
            'purchased' => (bool) ($context['purchased'] ?? false),
            'happening_now' => $happeningNow,
            'upcoming' => $upcoming,
            'featured' => (bool) $event->is_featured,
            'distance_km' => $distance,
            'hours_until' => $hoursUntil,
        ]);

        $ticketMeta = $this->eventTicketMeta((int) $event->id);

        return [
            'type' => 'event',
            'id' => (int) $event->id,
            'title' => $event->title,
            'subtitle' => $subtitle,
            'image' => $event->image,
            'url' => '/event/'.$event->slug,
            'score' => $score,
            'badges' => array_values(array_filter([
                $happeningNow ? 'Acontecendo agora' : null,
                ($ticketMeta['free'] ?? false) ? 'Gratuito' : null,
                ($ticketMeta['scarce'] ?? false) ? 'Últimos ingressos' : null,
                ($context['purchased'] ?? false) ? 'Ingresso adquirido' : null,
            ])),
            'meta' => [
                'start_date' => optional($event->start_date)->toIso8601String(),
                'end_date' => optional($event->end_date)->toIso8601String(),
                'category' => $event->category,
                'city' => $event->city,
                'uf' => $event->uf,
                'distance_km' => $distance,
                'happening_now' => $happeningNow,
                'ticket_owned' => (bool) ($context['purchased'] ?? false),
                'ticket' => $ticketMeta,
                'sales_count' => (int) ($context['passes'] ?? 0),
                'recent_sales_count' => (int) ($context['recent_passes'] ?? 0),
                'search_performance' => (float) ($context['search_performance'] ?? 0),
                'created_at' => optional($event->created_at)->toIso8601String(),
            ],
        ];
    }

    private function applyEventFilters(Builder $builder, array $filters, Carbon $now): void
    {
        if (! empty($filters['city'])) {
            $builder->whereRaw('LOWER(events.city) = LOWER(?)', [trim($filters['city'])]);
        }
        if (! empty($filters['uf'])) {
            $builder->where('events.uf', strtoupper($filters['uf']));
        }
        if (! empty($filters['category'])) {
            $builder->where('events.category', $filters['category']);
        }
        if (! empty($filters['production_id'])) {
            $builder->where('events.production_id', (int) $filters['production_id']);
        }
        if (! empty($filters['artist_id'])) {
            $builder->whereHas('artists', fn ($q) => $q->where('artists.id', (int) $filters['artist_id']));
        }
        if (! empty($filters['genre'])) {
            $builder->whereHas('artists', fn ($q) => $q->where('artists.genres', 'like', '%'.trim($filters['genre']).'%'));
        }
        if (! empty($filters['format'])) {
            $builder->where('events.event_format', $filters['format']);
        }

        [$from, $to] = $this->dateRange($filters, $now);
        if ($from) {
            $builder->where(fn ($q) => $q->whereNull('events.end_date')->orWhere('events.end_date', '>=', $from));
        }
        if ($to) {
            $builder->where('events.start_date', '<=', $to);
        }

        if (($filters['free'] ?? null) === true || ($filters['available'] ?? null) === true || isset($filters['max_price']) || isset($filters['min_price'])) {
            $builder->whereHas('tickets', function ($tickets) use ($filters, $now) {
                if (($filters['free'] ?? null) === true) {
                    $tickets->where('price', 0);
                }
                if (isset($filters['max_price'])) {
                    $tickets->where('price', '<=', (float) $filters['max_price']);
                }
                if (isset($filters['min_price'])) {
                    $tickets->where('price', '>=', (float) $filters['min_price']);
                }
                if (($filters['available'] ?? null) === true) {
                    $tickets->where(fn ($q) => $q->whereNull('limit_date')->orWhere('limit_date', '>=', $now));
                }
            });
        }

        if (isset($filters['lat'], $filters['lng']) && $filters['lat'] !== null && $filters['lng'] !== null) {
            $lat = (float) $filters['lat'];
            $lng = (float) $filters['lng'];
            $radius = (int) ($filters['radius_km'] ?? 50);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(events.latitude)) * cos(radians(events.longitude) - radians(?)) + sin(radians(?)) * sin(radians(events.latitude))))';
            $builder->whereNotNull('events.latitude')
                ->whereNotNull('events.longitude')
                ->whereRaw($distanceSql.' <= ?', [$lat, $lng, $lat, $radius]);
        }
    }

    private function dateRange(array $filters, Carbon $now): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            return [
                ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'], $timezone)->startOfDay() : null,
                ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'], $timezone)->endOfDay() : null,
            ];
        }

        return match ($filters['period'] ?? null) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'tomorrow' => [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()],
            'weekend' => $this->weekendRange($now),
            'next7' => [$now->copy(), $now->copy()->addDays(7)->endOfDay()],
            'next30' => [$now->copy(), $now->copy()->addDays(30)->endOfDay()],
            default => [null, null],
        };
    }

    private function weekendRange(Carbon $now): array
    {
        $friday = $now->isFriday() ? $now->copy() : ($now->isWeekend() ? $now->copy()->previous(Carbon::FRIDAY) : $now->copy()->next(Carbon::FRIDAY));

        return [$friday->copy()->startOfDay(), $friday->copy()->addDays(2)->endOfDay()];
    }

    private function viewerSignals(int $appId, ?User $viewer): array
    {
        if (! $viewer) {
            return ['followed' => [], 'blocked' => []];
        }

        $followed = [];
        if (Schema::hasTable('follows')) {
            DB::table('follows')
                ->where('app_id', $appId)
                ->where('user_id', $viewer->id)
                ->get(['target_type', 'target_id'])
                ->each(function ($row) use (&$followed) {
                    $followed[$row->target_type][(int) $row->target_id] = true;
                });
        }

        $blocked = [];
        if (Schema::hasTable('connection_blocks')) {
            $blocked = DB::table('connection_blocks')
                ->where('app_id', $appId)
                ->where(fn ($q) => $q->where('blocker_user_id', $viewer->id)->orWhere('blocked_user_id', $viewer->id))
                ->get(['blocker_user_id', 'blocked_user_id'])
                ->map(fn ($row) => (int) ($row->blocker_user_id == $viewer->id ? $row->blocked_user_id : $row->blocker_user_id))
                ->unique()
                ->values()
                ->all();
        }

        return ['followed' => $followed, 'blocked' => $blocked];
    }

    private function eventTicketMeta(int $eventId): array
    {
        if (! Schema::hasTable('tickets')) {
            return ['free' => false, 'available' => false, 'scarce' => false];
        }

        return Cache::remember('search:event-ticket-meta:'.$eventId, 30, function () use ($eventId) {
            $tickets = DB::table('tickets')->where('event_id', $eventId)->get(['price', 'quantity', 'limit_date']);
            if ($tickets->isEmpty()) {
                return ['free' => false, 'available' => false, 'scarce' => false];
            }

            $available = $tickets->filter(fn ($ticket) => ! $ticket->limit_date || Carbon::parse($ticket->limit_date)->isFuture());
            $remaining = $available->sum(fn ($ticket) => max(0, (int) ($ticket->quantity ?? 0)));

            return [
                'free' => $available->contains(fn ($ticket) => (float) $ticket->price <= 0),
                'available' => $available->isNotEmpty(),
                'scarce' => $remaining > 0 && $remaining <= 20,
                'min_price' => $available->isNotEmpty() ? (float) $available->min('price') : null,
            ];
        });
    }

    private function distanceFor(mixed $lat, mixed $lng, array $filters): ?float
    {
        if ($lat === null || $lng === null || ! isset($filters['lat'], $filters['lng']) || $filters['lat'] === null || $filters['lng'] === null) {
            return null;
        }

        $lat1 = deg2rad((float) $filters['lat']);
        $lng1 = deg2rad((float) $filters['lng']);
        $lat2 = deg2rad((float) $lat);
        $lng2 = deg2rad((float) $lng);
        $delta = $lng2 - $lng1;
        $distance = acos(min(1, max(-1, sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($delta)))) * 6371;

        return round($distance, 1);
    }

    private function performanceSignals(int $appId, string $type, array $ids): Collection
    {
        if (! $ids || ! Schema::hasTable('search_clicks')) {
            return collect();
        }

        return DB::table('search_clicks')
            ->where('app_id', $appId)
            ->where('target_type', $type)
            ->whereIn('target_id', $ids)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('target_id, COUNT(*) clicks, SUM(conversion_type IS NOT NULL) conversions')
            ->groupBy('target_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->target_id => min(50, (int) $row->clicks) + min(25, (int) $row->conversions) * 5,
            ]);
    }

    private function sortResults(Collection $items, array $filters): Collection
    {
        return match ($filters['sort'] ?? 'relevance') {
            'nearby' => $items->sortBy(fn ($item) => $item['meta']['distance_km'] ?? PHP_FLOAT_MAX)->values(),
            'newest' => $items->sortByDesc(fn ($item) => $item['meta']['created_at'] ?? $item['meta']['updated_at'] ?? '')->values(),
            'soonest' => $items->sortBy(fn ($item) => $item['meta']['start_date'] ?? '9999-12-31T23:59:59Z')->values(),
            'popular' => $items->sortByDesc(fn ($item) => ($item['meta']['recent_sales_count'] ?? 0) * 10 + ($item['meta']['search_performance'] ?? 0) + ($item['score'] ?? 0))->values(),
            default => $items->sortByDesc(fn ($item) => $item['score'] ?? 0)->values(),
        };
    }

    private function didYouMean(int $appId, string $query): ?string
    {
        if ($query === '' || ! Schema::hasTable('search_queries')) {
            return null;
        }

        return $this->popularTerms($appId, 90, null, 80)
            ->map(function ($row) use ($query) {
                $candidate = (string) $row->normalized_query;
                $distance = levenshtein($query, $candidate);
                $max = max(strlen($query), strlen($candidate), 1);
                $score = 1 - ($distance / $max);

                return ['query' => $row->query, 'score' => $score, 'distance' => $distance];
            })
            ->filter(fn ($item) => $item['distance'] <= 3 || $item['score'] >= .72)
            ->sortByDesc('score')
            ->value('query');
    }

    private function similar(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        $distance = levenshtein($left, $right);
        $max = max(strlen($left), strlen($right), 1);

        return $distance <= 2 || (1 - ($distance / $max)) >= .72;
    }

    private function encodeCursor(int $page): string
    {
        return rtrim(strtr(base64_encode(json_encode(['page' => max(1, $page)])), '+/', '-_'), '=');
    }

    private function hasMore(array $groups, string $type, int $limit): bool
    {
        $map = [
            'user' => 'people',
            'event' => 'events',
            'production' => 'productions',
            'artist' => 'artists',
            'post' => 'posts',
            'item' => 'items',
            'venue' => 'venues',
            'promoter' => 'promoters',
        ];
        $key = $map[$type] ?? null;

        return $key && ($groups[$key] ?? collect())->count() >= $limit;
    }

    private function artistTypeLabel(?string $type): string
    {
        return match ($type) {
            'band' => 'Banda',
            'duo' => 'Duo',
            'group' => 'Grupo',
            'collective' => 'Coletivo',
            'orchestra' => 'Orquestra',
            default => 'Artista',
        };
    }
}
