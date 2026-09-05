<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Event;
use App\Models\Interaction;
use App\Models\Production;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ApplicationAdminController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $appId = (int) $application->id;
        $today = now()->startOfDay();
        $last24Hours = now()->subDay();

        $activitySeries = collect(range(6, 0))->map(function (int $daysAgo) use ($appId) {
            $day = now()->subDays($daysAgo);

            return [
                'date' => $day->toDateString(),
                'label' => $day->locale('pt_BR')->translatedFormat('D'),
                'total' => Interaction::query()
                    ->where('app_id', $appId)
                    ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                    ->count(),
            ];
        })->values();

        $upcomingEvents = Event::query()
            ->where('app_id', $appId)
            ->where('start_date', '>=', now())
            ->orderBy('start_date')
            ->limit(5)
            ->get(['id', 'title', 'slug', 'start_date', 'city', 'uf', 'is_published', 'is_approved', 'is_cancelled']);

        $recentActivity = Interaction::query()
            ->where('app_id', $appId)
            ->with('user:id,first_name,last_name,email,user_name')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Interaction $interaction) => $this->activityResource($interaction))
            ->values();

        return response()->json([
            'application' => $this->applicationResource($application),
            'access' => [
                'role' => 'application_admin',
                'source' => $request->attributes->get('application_admin_source'),
            ],
            'metrics' => [
                'users_total' => $application->users()->count(),
                'users_active' => $application->users()->wherePivot('status', 'active')->count(),
                'users_new_30d' => $application->users()->wherePivot('joined_at', '>=', now()->subDays(30))->count(),
                'productions_total' => Production::query()->where('app_id', $appId)->count(),
                'productions_published' => Production::query()->where('app_id', $appId)->where('is_published', true)->count(),
                'events_total' => Event::query()->where('app_id', $appId)->count(),
                'events_published' => Event::query()->where('app_id', $appId)->where('is_published', true)->where('is_cancelled', false)->count(),
                'events_upcoming' => Event::query()->where('app_id', $appId)->where('start_date', '>=', now())->where('is_cancelled', false)->count(),
                'interactions_today' => Interaction::query()->where('app_id', $appId)->where('created_at', '>=', $today)->count(),
                'active_users_24h' => Interaction::query()->where('app_id', $appId)->whereNotNull('user_id')->where('created_at', '>=', $last24Hours)->distinct('user_id')->count('user_id'),
                'errors_24h' => Interaction::query()->where('app_id', $appId)->where('created_at', '>=', $last24Hours)->where(function ($query) {
                    $query->whereIn('outcome', ['error', 'failed', 'failure'])
                        ->orWhereIn('severity', ['error', 'critical']);
                })->count(),
            ],
            'activity_series' => $activitySeries,
            'upcoming_events' => $upcomingEvents,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = $application->users()
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.user_name', 'users.email', 'users.avatar', 'users.city', 'users.uf', 'users.profile_id', 'users.email_verified_at', 'users.created_at'])
            ->with('profile:id,name');

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('users.first_name', 'like', "%{$search}%")
                    ->orWhere('users.last_name', 'like', "%{$search}%")
                    ->orWhere('users.user_name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($status = $validated['status'] ?? null) {
            $query->wherePivot('status', $status);
        }

        $paginator = $query
            ->orderByDesc('application_user.joined_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (User $user) => $this->userResource($user))
        );

        return response()->json($this->paginationPayload($paginator));
    }

    public function updateUserAccess(Request $request, User $user): JsonResponse
    {
        $application = $this->application($request);
        $membership = $application->users()->where('users.id', $user->id)->first();

        if (! $membership) {
            return response()->json([
                'message' => 'O usuário não possui vínculo com esta aplicação.',
                'code' => 'APPLICATION_MEMBERSHIP_NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'is_admin' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $metadata = $this->metadata($membership->pivot?->metadata);
        $roles = collect(is_array($metadata['roles'] ?? null) ? $metadata['roles'] : [])
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->values();

        if (array_key_exists('is_admin', $validated)) {
            $isOwner = strtolower((string) $user->email) === strtolower((string) env('PETER_TECNET_OWNER_EMAIL', 'petertecnet@gmail.com'));
            $shouldBeAdmin = (bool) $validated['is_admin'] || $isOwner;

            $roles = $shouldBeAdmin
                ? $roles->push('application_admin')->unique()->values()
                : $roles->reject(fn ($role) => $role === 'application_admin')->values();
        }

        $metadata['roles'] = $roles->all();
        $updates = ['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];

        if (array_key_exists('status', $validated)) {
            $updates['status'] = $validated['status'];
        }

        $application->users()->updateExistingPivot($user->id, $updates);
        $fresh = $application->users()->where('users.id', $user->id)->firstOrFail();

        return response()->json([
            'message' => 'Acesso do usuário atualizado.',
            'user' => $this->userResource($fresh),
        ]);
    }

    public function productions(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Production::query()
            ->where('app_id', $application->id)
            ->select(['id', 'name', 'slug', 'user_id', 'city', 'uf', 'category', 'is_featured', 'is_published', 'is_approved', 'is_cancelled', 'created_at', 'updated_at']);

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        match ($validated['status'] ?? null) {
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            default => null,
        };

        $paginator = $query->latest()->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($this->paginationPayload($paginator));
    }

    public function updateProductionStatus(Request $request, Production $production): JsonResponse
    {
        $application = $this->application($request);
        $this->ensureBelongsToApplication($production->app_id, $application);

        $validated = $request->validate([
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $production->fill($validated)->save();

        return response()->json([
            'message' => 'Status da produção atualizado.',
            'production' => $production->fresh(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled', 'upcoming', 'past'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Event::query()
            ->where('app_id', $application->id)
            ->with('production:id,name,slug')
            ->select(['id', 'production_id', 'title', 'slug', 'category', 'start_date', 'end_date', 'city', 'uf', 'is_featured', 'is_published', 'is_approved', 'is_cancelled', 'is_private', 'created_at', 'updated_at']);

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        match ($validated['status'] ?? null) {
            'published' => $query->where('is_published', true)->where('is_cancelled', false),
            'draft' => $query->where('is_published', false)->where('is_cancelled', false),
            'cancelled' => $query->where('is_cancelled', true),
            'upcoming' => $query->where('start_date', '>=', now())->where('is_cancelled', false),
            'past' => $query->where('end_date', '<', now()),
            default => null,
        };

        $paginator = $query->orderByDesc('start_date')->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($this->paginationPayload($paginator));
    }

    public function updateEventStatus(Request $request, Event $event): JsonResponse
    {
        $application = $this->application($request);
        $this->ensureBelongsToApplication($event->app_id, $application);

        $validated = $request->validate([
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $event->fill($validated)->save();

        return response()->json([
            'message' => 'Status do evento atualizado.',
            'event' => $event->fresh(['production:id,name,slug']),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:60'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Interaction::query()
            ->where('app_id', $application->id)
            ->with('user:id,first_name,last_name,email,user_name');

        if ($type = trim((string) ($validated['type'] ?? ''))) {
            $query->where('interaction_type', $type);
        }

        if ($outcome = trim((string) ($validated['outcome'] ?? ''))) {
            $query->where('outcome', $outcome);
        }

        if ($from = $validated['from'] ?? null) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $validated['to'] ?? null) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('email', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->latest()->paginate((int) ($validated['per_page'] ?? 30));
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Interaction $interaction) => $this->activityResource($interaction))
        );

        return response()->json($this->paginationPayload($paginator));
    }

    private function application(Request $request): Application
    {
        /** @var Application|null $application */
        $application = $request->attributes->get('application');
        abort_unless($application instanceof Application, 404, 'Aplicação não encontrada.');
        return $application;
    }

    private function applicationResource(Application $application): array
    {
        return [
            'id' => $application->id,
            'name' => $application->name,
            'slug' => $application->slug,
            'url' => $application->url,
            'logo' => $application->logo,
            'operational_status' => $application->operational_status,
            'is_active' => $application->is_active,
        ];
    }

    private function userResource(User $user): array
    {
        $metadata = $this->metadata($user->pivot?->metadata);
        $roles = collect([
            $user->pivot?->role,
            ...(is_array($metadata['roles'] ?? null) ? $metadata['roles'] : []),
        ])->filter()->unique()->values()->all();

        return [
            'id' => $user->id,
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->user_name ?: $user->email),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_name' => $user->user_name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'city' => $user->city,
            'uf' => $user->uf,
            'profile' => $user->profile?->name,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'membership' => [
                'role' => $user->pivot?->role,
                'roles' => $roles,
                'status' => $user->pivot?->status,
                'joined_at' => $user->pivot?->joined_at,
                'is_admin' => in_array('application_admin', $roles, true)
                    || strtolower((string) $user->email) === strtolower((string) env('PETER_TECNET_OWNER_EMAIL', 'petertecnet@gmail.com')),
            ],
        ];
    }

    private function activityResource(Interaction $interaction): array
    {
        return [
            'id' => $interaction->id,
            'type' => $interaction->interaction_type,
            'outcome' => $interaction->outcome,
            'severity' => $interaction->severity,
            'name' => $interaction->name,
            'route' => $interaction->route,
            'method' => $interaction->method,
            'entity_type' => $interaction->entity_type,
            'entity_id' => $interaction->entity_id,
            'content' => $interaction->content,
            'user' => $interaction->user ? [
                'id' => $interaction->user->id,
                'name' => trim(($interaction->user->first_name ?? '') . ' ' . ($interaction->user->last_name ?? '')) ?: $interaction->user->user_name,
                'email' => $interaction->user->email,
            ] : null,
            'created_at' => $interaction->created_at,
        ];
    }

    private function metadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function ensureBelongsToApplication(?int $appId, Application $application): void
    {
        abort_unless((int) $appId === (int) $application->id, 404, 'Recurso não encontrado nesta aplicação.');
    }
}
