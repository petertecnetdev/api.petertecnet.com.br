<?php

namespace App\Domain\Discovery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GlobalSearchController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => 'required|string|min:1|max:120',
            'type' => 'nullable|in:all,event,production,artist,user',
            'per_type' => 'nullable|integer|min:1|max:20',
        ]);

        $term = trim($data['q']);
        $type = $data['type'] ?? 'all';
        $limit = (int) ($data['per_type'] ?? 8);
        $appId = $this->context->id();

        $groups = [
            'events' => collect(),
            'productions' => collect(),
            'artists' => collect(),
            'people' => collect(),
        ];

        if ($type === 'all' || $type === 'event') {
            $groups['events'] = $this->events($appId, $term, $limit);
        }

        if ($type === 'all' || $type === 'production') {
            $groups['productions'] = $this->productions($appId, $term, $limit);
        }

        if ($type === 'all' || $type === 'artist') {
            $groups['artists'] = $this->artists($appId, $term, $limit);
        }

        if ($type === 'all' || $type === 'user') {
            $groups['people'] = $this->people($appId, $term, $limit);
        }

        $results = collect()
            ->merge($groups['people'])
            ->merge($groups['events'])
            ->merge($groups['productions'])
            ->merge($groups['artists'])
            ->values();

        return response()->json([
            'query' => $term,
            'type' => $type,
            'results' => $results,
            'groups' => [
                'people' => $groups['people']->values(),
                'events' => $groups['events']->values(),
                'productions' => $groups['productions']->values(),
                'artists' => $groups['artists']->values(),
            ],
            'counts' => [
                'people' => $groups['people']->count(),
                'events' => $groups['events']->count(),
                'productions' => $groups['productions']->count(),
                'artists' => $groups['artists']->count(),
                'total' => $results->count(),
            ],
        ])->header('Cache-Control', 'public, max-age=20, stale-while-revalidate=60');
    }

    private function events(int $appId, string $term, int $limit): Collection
    {
        $like = '%'.$term.'%';

        return Event::query()
            ->where('events.app_id', $appId)
            ->publiclyVisible()
            ->with('production:id,name,slug,logo')
            ->where(function (Builder $query) use ($like) {
                $query->where('events.title', 'like', $like)
                    ->orWhere('events.venue', 'like', $like)
                    ->orWhere('events.city', 'like', $like)
                    ->orWhere('events.category', 'like', $like)
                    ->orWhereHas('production', fn (Builder $production) => $production->where('name', 'like', $like))
                    ->orWhereHas('artists', fn (Builder $artist) => $artist->where('stage_name', 'like', $like));
            })
            ->orderByRaw(
                'CASE WHEN LOWER(events.title) = LOWER(?) THEN 0 WHEN LOWER(events.title) LIKE LOWER(?) THEN 1 ELSE 2 END',
                [$term, $term.'%']
            )
            ->orderByRaw('CASE WHEN events.end_date >= ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('events.start_date')
            ->limit($limit)
            ->get([
                'events.id',
                'events.title',
                'events.slug',
                'events.image',
                'events.start_date',
                'events.end_date',
                'events.city',
                'events.uf',
                'events.venue',
                'events.category',
                'events.production_id',
            ])
            ->map(fn (Event $event) => [
                'type' => 'event',
                'id' => (int) $event->id,
                'title' => $event->title,
                'subtitle' => collect([
                    $event->production?->name,
                    $event->venue,
                    trim(implode(' - ', array_filter([$event->city, $event->uf]))),
                ])->filter()->take(2)->implode(' · '),
                'image' => $event->image,
                'url' => '/event/'.$event->slug,
                'meta' => [
                    'start_date' => optional($event->start_date)->toIso8601String(),
                    'end_date' => optional($event->end_date)->toIso8601String(),
                    'category' => $event->category,
                ],
            ]);
    }

    private function productions(int $appId, string $term, int $limit): Collection
    {
        $like = '%'.$term.'%';

        return Production::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where(fn (Builder $query) => $query->where('is_cancelled', false)->orWhereNull('is_cancelled'))
            ->where(function (Builder $query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('fantasy', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('city', 'like', $like);
            })
            ->orderByRaw(
                'CASE WHEN LOWER(name) = LOWER(?) THEN 0 WHEN LOWER(name) LIKE LOWER(?) THEN 1 ELSE 2 END',
                [$term, $term.'%']
            )
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'fantasy', 'slug', 'logo', 'city', 'uf'])
            ->map(fn (Production $production) => [
                'type' => 'production',
                'id' => (int) $production->id,
                'title' => $production->name,
                'subtitle' => collect([
                    $production->fantasy && $production->fantasy !== $production->name ? $production->fantasy : null,
                    trim(implode(' - ', array_filter([$production->city, $production->uf]))),
                ])->filter()->implode(' · '),
                'image' => $production->logo,
                'url' => '/production/'.$production->slug.'/public',
                'meta' => [],
            ]);
    }

    private function artists(int $appId, string $term, int $limit): Collection
    {
        $like = '%'.$term.'%';

        return Artist::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where(function (Builder $query) use ($like) {
                $query->where('stage_name', 'like', $like)
                    ->orWhere('bio', 'like', $like)
                    ->orWhere('city', 'like', $like);
            })
            ->orderByRaw(
                'CASE WHEN LOWER(stage_name) = LOWER(?) THEN 0 WHEN LOWER(stage_name) LIKE LOWER(?) THEN 1 ELSE 2 END',
                [$term, $term.'%']
            )
            ->orderBy('stage_name')
            ->limit($limit)
            ->get(['id', 'stage_name', 'slug', 'artist_type', 'photo', 'city', 'uf'])
            ->map(fn (Artist $artist) => [
                'type' => 'artist',
                'id' => (int) $artist->id,
                'title' => $artist->stage_name,
                'subtitle' => collect([
                    $this->artistTypeLabel($artist->artist_type),
                    trim(implode(' - ', array_filter([$artist->city, $artist->uf]))),
                ])->filter()->implode(' · '),
                'image' => $artist->photo,
                'url' => '/artist/'.$artist->slug,
                'meta' => ['artist_type' => $artist->artist_type],
            ]);
    }

    private function people(int $appId, string $term, int $limit): Collection
    {
        $like = '%'.$term.'%';

        return User::query()
            ->whereHas('applications', fn (Builder $application) => $application
                ->where('applications.id', $appId)
                ->where('application_user.status', 'active'))
            ->whereNotNull('email_verified_at')
            ->where(function (Builder $query) use ($like) {
                $query->where('user_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", [$like]);
            })
            ->orderByRaw(
                "CASE WHEN LOWER(COALESCE(user_name, '')) = LOWER(?) THEN 0 WHEN LOWER(COALESCE(user_name, '')) LIKE LOWER(?) THEN 1 WHEN LOWER(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) LIKE LOWER(?) THEN 2 ELSE 3 END",
                [$term, $term.'%', $term.'%']
            )
            ->orderBy('first_name')
            ->limit($limit)
            ->get(['id', 'user_name', 'first_name', 'last_name', 'avatar', 'city', 'uf'])
            ->map(function (User $user) {
                $name = trim(implode(' ', array_filter([$user->first_name, $user->last_name])));

                return [
                    'type' => 'user',
                    'id' => (int) $user->id,
                    'title' => $name !== '' ? $name : ($user->user_name ?: 'Usuário Cutinapp'),
                    'subtitle' => collect([
                        $user->user_name ? '@'.ltrim($user->user_name, '@') : null,
                        trim(implode(' - ', array_filter([$user->city, $user->uf]))),
                    ])->filter()->implode(' · '),
                    'image' => $user->avatar,
                    'url' => '/profile/'.$user->id,
                    'meta' => ['user_name' => $user->user_name],
                ];
            });
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
