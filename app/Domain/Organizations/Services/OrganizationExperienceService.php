<?php

namespace App\Domain\Organizations\Services;

use App\Models\Event;
use App\Models\Interaction;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OrganizationExperienceService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function publicExperience(string $slug, ?string $bearerToken = null): array
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $viewer = $this->optionalUser($bearerToken);
        Interaction::registerView($organization, $viewer, ['surface' => 'organization_public']);

        return [
            'analytics' => $this->analytics($organization, $viewer),
            'media' => $this->media($organization),
            'social_proof' => $this->socialProof($organization),
            'reviews' => $this->reviews($organization),
            'related_productions' => $this->relatedOrganizations($organization),
        ];
    }

    public function workspace(User $user, int $id): array
    {
        $organization = $this->managed($user, $id);
        $appId = $this->context->id();

        $events = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $organization->id)
            ->orderByDesc('start_date')
            ->limit(60)
            ->get();

        $organization->setAttribute('events_count', $events->count());
        $organization->setAttribute('followers_count', DB::table('follows')->where([
            'app_id' => $appId,
            'target_type' => 'production',
            'target_id' => $organization->id,
        ])->count());

        return [
            'organization' => $organization,
            'events' => $events,
            'analytics' => $this->analytics($organization, $user),
            'media' => $this->media($organization),
            'social_proof' => $this->socialProof($organization),
            'reviews' => $this->reviews($organization),
        ];
    }

    public function updateProfile(User $user, int $id, array $data): Production
    {
        $organization = $this->managed($user, $id);

        if (array_key_exists('uf', $data) && $data['uf']) {
            $data['uf'] = strtoupper(trim((string) $data['uf']));
        }

        if (array_key_exists('cep', $data) && $data['cep']) {
            $data['cep'] = preg_replace('/\D+/', '', (string) $data['cep']);
        }

        foreach ([
            'city',
            'address',
            'address_number',
            'neighborhood',
            'address_complement',
            'address_reference',
            'formatted_address',
            'place_id',
            'google_maps_url',
            'type',
        ] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }

        $organization->update($data);

        return $organization->fresh();
    }

    private function analytics(Production $organization, ?User $viewer = null): array
    {
        $base = Interaction::query()
            ->where('app_id', $this->context->id())
            ->where('entity_id', $organization->id)
            ->where('interaction_type', 'view')
            ->whereIn('entity_type', ['Production', 'production', 'Establishment']);

        $canSeeViewers = $viewer && (
            (int) $organization->user_id === (int) $viewer->id
            || (method_exists($viewer, 'hasProfile') && $viewer->hasProfile('Administrador'))
        );

        $viewers = collect();
        if ($canSeeViewers) {
            $rows = (clone $base)
                ->whereNotNull('user_id')
                ->selectRaw('user_id, COUNT(*) as views_count, MAX(created_at) as last_viewed_at')
                ->groupBy('user_id')
                ->orderByDesc('last_viewed_at')
                ->limit(100)
                ->get();

            $users = User::query()
                ->whereIn('id', $rows->pluck('user_id'))
                ->get(['id', 'first_name', 'last_name', 'avatar'])
                ->keyBy('id');

            $viewers = $rows->map(function ($row) use ($users) {
                $user = $users->get($row->user_id);
                if (! $user) {
                    return null;
                }

                return [
                    'id' => (int) $user->id,
                    'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'Participante',
                    'avatar' => $user->avatar,
                    'views_count' => (int) $row->views_count,
                    'last_viewed_at' => $row->last_viewed_at,
                ];
            })->filter()->values();
        }

        return [
            'total_views' => (clone $base)->count(),
            'unique_viewers' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
            'viewers' => $viewers,
        ];
    }

    private function socialProof(Production $organization): array
    {
        $appId = $this->context->id();

        $publicEventIds = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $organization->id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))
            ->pluck('id');

        $attendeeIds = DB::table('event_passes')
            ->whereIn('event_id', $publicEventIds)
            ->whereNotNull('user_id')
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        $rating = DB::table('event_ratings as ratings')
            ->join('events', 'events.id', '=', 'ratings.event_id')
            ->where('ratings.app_id', $appId)
            ->where('events.app_id', $appId)
            ->where('events.production_id', $organization->id)
            ->where('events.is_published', true)
            ->where('events.is_cancelled', false)
            ->where(fn ($privacy) => $privacy->where('events.is_private', false)->orWhereNull('events.is_private'))
            ->selectRaw('ROUND(AVG(ratings.rating), 1) as average, COUNT(*) as total, SUM(CASE WHEN ratings.verified_attendee = 1 THEN 1 ELSE 0 END) as verified')
            ->first();

        return [
            'followers_count' => DB::table('follows')->where([
                'app_id' => $appId,
                'target_type' => 'production',
                'target_id' => $organization->id,
            ])->count(),
            'attendees_count' => $attendeeIds->count(),
            'attendees_preview' => [],
            'rating_average' => $rating?->average ? (float) $rating->average : 0,
            'ratings_count' => (int) ($rating?->total ?? 0),
            'verified_ratings_count' => (int) ($rating?->verified ?? 0),
        ];
    }

    private function reviews(Production $organization): array
    {
        $appId = $this->context->id();

        return DB::table('event_ratings as ratings')
            ->join('events', 'events.id', '=', 'ratings.event_id')
            ->where('ratings.app_id', $appId)
            ->where('events.app_id', $appId)
            ->where('events.production_id', $organization->id)
            ->where('events.is_published', true)
            ->where('events.is_cancelled', false)
            ->where(fn ($privacy) => $privacy->where('events.is_private', false)->orWhereNull('events.is_private'))
            ->orderByDesc('ratings.verified_attendee')
            ->orderByDesc('ratings.updated_at')
            ->limit(16)
            ->get([
                'ratings.event_id',
                'ratings.rating',
                'ratings.verified_attendee',
                'ratings.updated_at',
                'events.title as event_title',
                'events.slug as event_slug',
            ])
            ->map(fn ($row) => [
                'event_id' => (int) $row->event_id,
                'event_title' => $row->event_title,
                'event_slug' => $row->event_slug,
                'rating' => (int) $row->rating,
                'verified_attendee' => (bool) $row->verified_attendee,
                'created_at' => $row->updated_at,
                'name' => $row->verified_attendee ? 'Participante verificado' : 'Participante',
                'avatar' => null,
            ])
            ->all();
    }

    private function relatedOrganizations(Production $organization): array
    {
        $appId = $this->context->id();

        $query = Production::query()
            ->where('app_id', $appId)
            ->whereKeyNot($organization->id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->select([
                'establishments.id',
                'establishments.name',
                'establishments.slug',
                'establishments.city',
                'establishments.uf',
                'establishments.logo',
                'establishments.background',
            ])
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('follows')
                    ->selectRaw('COUNT(*)')
                    ->where('app_id', $appId)
                    ->where('target_type', 'production')
                    ->whereColumn('target_id', 'establishments.id');
            }, 'followers_count')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('events')
                    ->selectRaw('COUNT(*)')
                    ->where('app_id', $appId)
                    ->where('is_published', true)
                    ->where('is_cancelled', false)
                    ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))
                    ->where('end_date', '>', now())
                    ->whereColumn('production_id', 'establishments.id');
            }, 'upcoming_events_count');

        if ($organization->city) {
            $query->whereRaw('LOWER(city) = LOWER(?)', [$organization->city]);
        } elseif ($organization->uf) {
            $query->where('uf', $organization->uf);
        }

        return $query
            ->orderByDesc('is_featured')
            ->orderByDesc('upcoming_events_count')
            ->orderByDesc('followers_count')
            ->limit(6)
            ->get()
            ->map(fn (Production $related) => [
                'id' => (int) $related->id,
                'name' => $related->name,
                'slug' => $related->slug,
                'city' => $related->city,
                'uf' => $related->uf,
                'logo' => $related->logo,
                'background' => $related->background,
                'followers_count' => (int) ($related->followers_count ?? 0),
                'upcoming_events_count' => (int) ($related->upcoming_events_count ?? 0),
            ])
            ->all();
    }

    private function media(Production $organization): array
    {
        return DB::table('organization_media')
            ->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'url' => Storage::disk('public')->url($row->path),
                'caption' => $row->caption,
                'position' => (int) $row->position,
            ])
            ->all();
    }

    private function managed(User $user, int $id): Production
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($id);

        $admin = method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');

        abort_unless(
            $admin || (int) $organization->user_id === (int) $user->id,
            403,
            'Você não pode gerenciar esta organização.'
        );

        return $organization;
    }

    private function optionalUser(?string $token): ?User
    {
        if (! $token) {
            return null;
        }

        try {
            $user = JWTAuth::setToken($token)->authenticate();

            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }
}
