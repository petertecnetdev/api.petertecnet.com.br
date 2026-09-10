<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Commerce\Services\TicketInventoryService;
use App\Models\Application;
use App\Models\Artist;
use App\Models\Event;
use App\Models\EventAgendaSetting;
use App\Models\EventSchedule;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OrganizationService
{
    public function __construct(private readonly TicketInventoryService $ticketInventory) {}

    public function publicIndex(int $appId, array $data, array $queryParameters = []): LengthAwarePaginator
    {
        $query = Production::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->select('establishments.*')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('follows')
                    ->selectRaw('COUNT(*)')
                    ->where('app_id', $appId)
                    ->where('target_type', 'production')
                    ->whereColumn('target_id', 'establishments.id');
            }, 'followers_count')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('interactions')
                    ->selectRaw('COUNT(*)')
                    ->where('app_id', $appId)
                    ->where('interaction_type', 'view')
                    ->whereIn('entity_type', ['Production', 'production', 'Establishment'])
                    ->whereColumn('entity_id', 'establishments.id');
            }, 'views_count')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('events')
                    ->selectRaw('COUNT(*)')
                    ->where('app_id', $appId)
                    ->where('is_published', true)
                    ->where('is_cancelled', false)
                    ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))
                    ->whereColumn('production_id', 'establishments.id');
            }, 'events_count')
            ->selectSub(function ($sub) {
                $sub->from('items')
                    ->selectRaw('COUNT(*)')
                    ->where('entity_name', 'establishment')
                    ->where('status', true)
                    ->whereNull('deleted_at')
                    ->whereColumn('entity_id', 'establishments.id');
            }, 'items_count')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('event_ratings as ratings')
                    ->join('events as rated_events', 'rated_events.id', '=', 'ratings.event_id')
                    ->selectRaw('ROUND(AVG(ratings.rating), 1)')
                    ->where('ratings.app_id', $appId)
                    ->where('rated_events.app_id', $appId)
                    ->where('rated_events.is_published', true)
                    ->where('rated_events.is_cancelled', false)
                    ->where(fn ($privacy) => $privacy
                        ->where('rated_events.is_private', false)
                        ->orWhereNull('rated_events.is_private'))
                    ->whereColumn('rated_events.production_id', 'establishments.id');
            }, 'rating_average')
            ->selectSub(function ($sub) use ($appId) {
                $sub->from('event_ratings as ratings')
                    ->join('events as rated_events', 'rated_events.id', '=', 'ratings.event_id')
                    ->selectRaw('COUNT(*)')
                    ->where('ratings.app_id', $appId)
                    ->where('rated_events.app_id', $appId)
                    ->where('rated_events.is_published', true)
                    ->where('rated_events.is_cancelled', false)
                    ->where(fn ($privacy) => $privacy
                        ->where('rated_events.is_private', false)
                        ->orWhereNull('rated_events.is_private'))
                    ->whereColumn('rated_events.production_id', 'establishments.id');
            }, 'ratings_count')
            ->withCount(['events as upcoming_events_count' => fn ($events) => $events
                ->where('app_id', $appId)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))
                ->where('end_date', '>', now())]);

        if (! empty($data['q'])) {
            $term = '%'.trim($data['q']).'%';
            $query->where(function ($search) use ($term) {
                $search->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw("LOWER(COALESCE(fantasy, '')) LIKE LOWER(?)", [$term]);
            });
        }

        if (! empty($data['city'])) {
            $query->whereRaw('LOWER(city) = LOWER(?)', [trim($data['city'])]);
        }

        if (! empty($data['uf'])) {
            $query->where('uf', strtoupper($data['uf']));
        }

        if (isset($data['lat'], $data['lng'])) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $radius = (int) ($data['radius_km'] ?? 80);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';

            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->selectRaw("{$distanceSql} AS distance_km", [$lat, $lng, $lat])
                ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius])
                ->orderBy('distance_km');
        } else {
            $query->orderByDesc('is_featured')
                ->orderByDesc('upcoming_events_count')
                ->orderBy('name');
        }

        $organizations = $query
            ->paginate($data['per_page'] ?? 12)
            ->appends($queryParameters);

        $organizations->getCollection()->each(
            fn (Production $organization) => $organization->makeHidden('metrics')
        );

        return $organizations;
    }

    public function publicShow(int $appId, string $slug, ?string $bearerToken = null): array
    {
        $organization = Production::query()
            ->where('app_id', $appId)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $organization->setAttribute('followers_count', DB::table('follows')->where([
            'app_id' => $appId,
            'target_type' => 'production',
            'target_id' => $organization->id,
        ])->count());

        $user = $this->optionalTokenUser($bearerToken);
        $organization->setAttribute('is_following', $user ? DB::table('follows')->where([
            'app_id' => $appId,
            'user_id' => $user->id,
            'target_type' => 'production',
            'target_id' => $organization->id,
        ])->exists() : false);

        $visibleEvents = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $organization->id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'));

        $agendaEnabled = EventAgendaSetting::query()
            ->where('app_id', $appId)
            ->where('production_id', $organization->id)
            ->value('is_active');

        $weeklyAgenda = collect();
        if ($agendaEnabled !== false) {
            $weeklyAgenda = EventSchedule::query()
                ->where('app_id', $appId)
                ->where('production_id', $organization->id)
                ->where('is_active', true)
                ->whereNotNull('source_event_id')
                ->with(['sourceEvent' => fn ($events) => $events
                    ->where('app_id', $appId)
                    ->where('is_published', true)
                    ->where('is_cancelled', false)
                    ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))])
                ->get()
                ->filter(fn (EventSchedule $schedule) => $schedule->sourceEvent !== null)
                ->map(fn (EventSchedule $schedule) => [
                    'day_of_week' => (int) $schedule->day_of_week,
                    'event' => $schedule->sourceEvent,
                ])
                ->values();
        }

        $upcoming = (clone $visibleEvents)
            ->where('end_date', '>', now())
            ->orderBy('start_date')
            ->limit(24)
            ->get();

        $ticketEventIds = $upcoming->pluck('id')
            ->merge($weeklyAgenda->pluck('event.id'))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ticketEventIds->isNotEmpty()) {
            $tickets = Ticket::query()
                ->where('app_id', $appId)
                ->whereIn('event_id', $ticketEventIds)
                ->get(['id', 'app_id', 'event_id', 'price', 'quantity', 'limit_date']);
            $availabilityByEvent = $this->ticketInventory->availabilityByEvent($tickets);

            $applyAvailability = function (Event $event) use ($availabilityByEvent) {
                $summary = $availabilityByEvent->get((int) $event->id, [
                    'status' => 'tickets_pending',
                    'configured_lots_count' => 0,
                    'sellable_lots_count' => 0,
                    'sellable_free_lots_count' => 0,
                ]);
                $event->setAttribute('ticket_availability_status', $summary['status']);
                $event->setAttribute('sellable_ticket_lots_count', $summary['sellable_lots_count']);
                $event->setAttribute('sellable_free_ticket_lots_count', $summary['sellable_free_lots_count']);
            };

            $upcoming->each($applyAvailability);
            $weeklyAgenda->each(fn (array $slot) => $applyAvailability($slot['event']));
        }

        $past = (clone $visibleEvents)
            ->where('end_date', '<=', now())
            ->orderByDesc('start_date')
            ->limit(24)
            ->get();

        $artists = Artist::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->whereHas('events', fn ($events) => $events
                ->where('events.app_id', $appId)
                ->where('events.production_id', $organization->id)
                ->where('events.is_published', true)
                ->where('events.is_cancelled', false))
            ->distinct()
            ->limit(30)
            ->get();

        return [
            'organization' => $organization,
            'weekly_agenda' => $weeklyAgenda,
            'upcoming' => $upcoming,
            'past' => $past,
            'artists' => $artists,
        ];
    }

    public function mine(int $appId, User $user)
    {
        return Production::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->withCount(['events' => fn ($events) => $events->where('app_id', $appId)])
            ->latest()
            ->get();
    }

    public function showOwned(int $appId, User $user, int $id): Production
    {
        return $this->owned($appId, $user, $id);
    }

    public function create(
        int $appId,
        string $appSlug,
        User $user,
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $background = null,
    ): Production {
        $data['user_id'] = $user->id;
        $data['app_id'] = $appId;
        $data['app_slug'] = $appSlug;
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['is_published'] = true;
        $data['is_cancelled'] = false;
        unset($data['logo'], $data['background']);

        $organization = Production::create($data);
        $this->storeImages($organization, $appSlug, $logo, $background);

        Application::query()->whereKey($appId)->firstOrFail()->users()->syncWithoutDetaching([
            $user->id => [
                'role' => 'producer',
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);

        return $organization->fresh();
    }

    public function update(
        int $appId,
        string $appSlug,
        User $user,
        int $id,
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $background = null,
    ): Production {
        $organization = $this->owned($appId, $user, $id);

        if (! empty($data['name']) && $data['name'] !== $organization->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $organization->id);
        }

        unset($data['logo'], $data['background'], $data['user_id'], $data['app_id'], $data['app_slug']);
        $organization->update($data);
        $this->storeImages($organization, $appSlug, $logo, $background);

        return $organization->fresh();
    }

    public function delete(int $appId, User $user, int $id): void
    {
        $organization = $this->owned($appId, $user, $id);

        abort_if(
            $organization->events()->whereHas('tickets.passes')->exists(),
            409,
            'Organizações com ingressos emitidos não podem ser excluídas.'
        );

        DB::transaction(function () use ($organization) {
            Event::query()
                ->where('production_id', $organization->id)
                ->update([
                    'is_published' => false,
                    'is_cancelled' => true,
                    'updated_at' => now(),
                ]);

            $organization->update([
                'is_published' => false,
                'is_cancelled' => true,
            ]);
            $organization->delete();
        });
    }

    private function owned(int $appId, User $user, int $id): Production
    {
        $organization = Production::query()
            ->where('app_id', $appId)
            ->findOrFail($id);

        $admin = method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($admin || (int) $organization->user_id === (int) $user->id, 403, 'Você não pode gerenciar esta organização.');

        return $organization;
    }

    private function storeImages(
        Production $organization,
        string $appSlug,
        ?UploadedFile $logo,
        ?UploadedFile $background,
    ): void {
        foreach ([
            'logo' => [$logo, 600, 600],
            'background' => [$background, 1920, 700],
        ] as $field => [$file, $width, $height]) {
            if (! $file) {
                continue;
            }

            if ($organization->{$field} && str_starts_with($organization->{$field}, 'images/apps/')) {
                Storage::disk('public')->delete($organization->{$field});
            }

            $path = 'images/apps/'.$appSlug.'/organizations/'.$field.'-'.Str::uuid().'.webp';
            $absolute = Storage::disk('public')->path($path);

            if (! is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0755, true);
            }

            Image::make($file->getRealPath())
                ->orientate()
                ->fit($width, $height)
                ->encode('webp', 86)
                ->save($absolute);

            $organization->{$field} = $path;
        }

        if ($organization->isDirty(['logo', 'background'])) {
            $organization->save();
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'organizacao-'.Str::lower(Str::random(8));
        $slug = $base;
        $i = 2;

        while (Production::withTrashed()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function optionalTokenUser(?string $token): ?User
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
