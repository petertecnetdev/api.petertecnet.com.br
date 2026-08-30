<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EcosystemController extends Controller
{
    public function publicSite(): JsonResponse
    {
        $settings = EcosystemSetting::query()
            ->where('group', 'site')
            ->where('is_public', true)
            ->get()
            ->mapWithKeys(fn ($item) => [$item->key => $item->value]);

        return response()->json(['site' => array_replace_recursive($this->siteDefaults(), $settings->all())]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $now = now();
        $applications = Application::query()
            ->withCount(['users', 'establishments', 'items'])
            ->orderBy('name')
            ->get();

        $activeToday = Interaction::query()->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->startOfDay())->distinct()->count('user_id');
        $active7 = Interaction::query()->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->subDays(7))->distinct()->count('user_id');
        $active30 = Interaction::query()->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->subDays(30))->distinct()->count('user_id');
        $activeIds30 = Interaction::query()->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->subDays(30))->distinct()->pluck('user_id');

        $activityByApp = Interaction::query()
            ->selectRaw('app_id, COUNT(*) total, COUNT(DISTINCT user_id) unique_users')
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->whereNotNull('app_id')
            ->groupBy('app_id')
            ->get()
            ->keyBy('app_id');

        $applications->transform(function ($app) use ($activityByApp) {
            $usage = $activityByApp->get($app->id);
            $app->activity_count_30d = (int) ($usage?->total ?? 0);
            $app->active_users_30d = (int) ($usage?->unique_users ?? 0);
            return $app;
        });

        $seriesStart = $now->copy()->subHours(23)->startOfHour();
        $seriesRows = Interaction::query()
            ->where('created_at', '>=', $seriesStart)
            ->get(['interaction_type', 'created_at'])
            ->groupBy(fn ($interaction) => $interaction->created_at->format('Y-m-d H:00:00'));

        $interactionSeries = collect(range(0, 23))->map(function ($offset) use ($seriesStart, $seriesRows) {
            $moment = $seriesStart->copy()->addHours($offset);
            $bucket = $seriesRows->get($moment->format('Y-m-d H:00:00'), collect());

            return [
                'timestamp' => $moment->toIso8601String(),
                'label' => $moment->format('H:i'),
                'total' => $bucket->count(),
                'errors' => $bucket->where('interaction_type', 'request_error')->count(),
            ];
        })->values();

        return response()->json([
            'summary' => [
                'applications' => Application::count(),
                'active_applications' => Application::where('is_active', true)->count(),
                'users' => User::count(),
                'profiles' => Profile::count(),
                'establishments' => Establishment::count(),
                'published_establishments' => Establishment::where('is_published', true)->count(),
                'approved_establishments' => Establishment::where('is_approved', true)->count(),
                'access_links' => DB::table('application_user')->count(),
                'active_users_today' => $activeToday,
                'active_users_7d' => $active7,
                'active_users_30d' => $active30,
                'inactive_users_30d' => max(User::count() - $activeIds30->count(), 0),
                'new_users_30d' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
                'interactions_today' => Interaction::where('created_at', '>=', $now->copy()->startOfDay())->count(),
                'interactions_30d' => Interaction::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            ],
            'applications' => $applications,
            'interaction_series' => $interactionSeries,
            'activity_types' => Interaction::query()
                ->selectRaw('interaction_type, COUNT(*) total')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->groupBy('interaction_type')->orderByDesc('total')->limit(10)->get(),
            'recent_activity' => $this->interactionQuery()->latest()->limit(16)->get()->map(fn ($item) => $this->interactionPayload($item)),
            'recent_users' => User::query()->with('profile:id,name')->latest('id')->limit(8)->get([
                'id', 'first_name', 'last_name', 'user_name', 'email', 'profile_id', 'created_at',
            ]),
            'recent_establishments' => Establishment::query()->with(['app:id,name', 'user:id,first_name,last_name,email'])
                ->latest('id')->limit(8)->get([
                    'id', 'name', 'fantasy', 'app_id', 'user_id', 'city', 'uf', 'is_published', 'is_approved', 'created_at',
                ]),
            'recent_audit' => EcosystemAuditLog::query()->with('user:id,first_name,last_name,email')->latest()->limit(12)->get(),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);

        $query = $this->interactionQuery();
        if (! empty($data['user_id'])) $query->where('user_id', $data['user_id']);
        if (! empty($data['app_id'])) $query->where('app_id', $data['app_id']);
        if (! empty($data['type'])) $query->where('interaction_type', $data['type']);
        if (! empty($data['from'])) $query->where('created_at', '>=', $data['from']);
        if (! empty($data['to'])) $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));
        if (! empty($data['search'])) {
            $search = $data['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 40);
        $base = clone $query;
        $total = (clone $base)->count();
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query->latest('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'summary' => [
                'total' => $total,
                'users' => (clone $base)->whereNotNull('user_id')->distinct()->count('user_id'),
                'applications' => (clone $base)->whereNotNull('app_id')->distinct()->count('app_id'),
            ],
            'types' => Interaction::query()->select('interaction_type')->distinct()->orderBy('interaction_type')->pluck('interaction_type')->filter()->values(),
            'activity' => $rows->map(fn ($item) => $this->interactionPayload($item)),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
                'next_page' => $page < $lastPage ? $page + 1 : null,
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $query = User::query()
            ->with(['profile:id,name', 'applications:id,name,slug'])
            ->withCount(['interactions', 'establishments']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $users = $query->latest('id')->limit(300)->get();
        $lastActivity = Interaction::query()->selectRaw('user_id, MAX(created_at) last_activity_at')
            ->whereIn('user_id', $users->pluck('id'))->groupBy('user_id')->pluck('last_activity_at', 'user_id');

        $users->each(function ($user) use ($lastActivity) {
            $user->last_activity_at = $lastActivity[$user->id] ?? null;
        });

        return response()->json(['users' => $users]);
    }

    public function userDetail(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request);

        $user->load(['profile:id,name,permissions', 'applications:id,name,slug,logo,url', 'establishments.app:id,name,slug']);
        $all = $this->interactionQuery()->where('user_id', $user->id)->latest()->limit(500)->get();
        $logins = $all->whereIn('interaction_type', ['login', 'login_google']);
        $last = $all->first();

        $ips = $all->map(fn ($i) => data_get($i->content, 'ip'))->filter()->countBy()->sortDesc()->take(12);
        $locations = $all->map(function ($i) {
            $city = data_get($i->content, 'city');
            $uf = data_get($i->content, 'uf');
            return $city ? trim($city . ($uf ? "/{$uf}" : '')) : null;
        })->filter()->countBy()->sortDesc()->take(12);
        $devices = $all->map(fn ($i) => $this->deviceLabel(data_get($i->content, 'user_agent')))->filter()->countBy()->sortDesc()->take(12);

        $byType = Interaction::query()->where('user_id', $user->id)
            ->selectRaw('interaction_type, COUNT(*) total')->groupBy('interaction_type')->orderByDesc('total')->get();
        $byApp = Interaction::query()->where('user_id', $user->id)->whereNotNull('app_id')
            ->selectRaw('app_id, COUNT(*) total, MAX(created_at) last_activity_at')->groupBy('app_id')->orderByDesc('total')->get();
        $appMap = Application::query()->whereIn('id', $byApp->pluck('app_id'))->get(['id', 'name', 'slug', 'logo'])->keyBy('id');

        $appUsage = $byApp->map(fn ($row) => [
            'application' => $appMap->get($row->app_id),
            'total' => (int) $row->total,
            'last_activity_at' => $row->last_activity_at,
        ])->values();

        $recentLoginIps = $logins->take(20)->map(fn ($i) => data_get($i->content, 'ip'))->filter();
        $securityAlerts = [];
        if ($recentLoginIps->unique()->count() >= 5) $securityAlerts[] = ['level' => 'warning', 'message' => 'Múltiplos IPs diferentes foram usados nos logins recentes.'];
        if ($logins->count() >= 2 && $logins->take(10)->groupBy(fn ($i) => $i->created_at?->format('Y-m-d H:i'))->max(fn ($g) => $g->count()) >= 3) $securityAlerts[] = ['level' => 'warning', 'message' => 'Foram detectados vários logins em um intervalo curto.'];
        if ($all->isEmpty()) $securityAlerts[] = ['level' => 'info', 'message' => 'Ainda não há atividade registrada para este usuário.'];

        return response()->json([
            'user' => $user,
            'summary' => [
                'total_interactions' => Interaction::where('user_id', $user->id)->count(),
                'interactions_7d' => Interaction::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(7))->count(),
                'interactions_30d' => Interaction::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'logins_7d' => Interaction::where('user_id', $user->id)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(7))->count(),
                'logins_30d' => Interaction::where('user_id', $user->id)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(30))->count(),
                'logins_90d' => Interaction::where('user_id', $user->id)->whereIn('interaction_type', ['login', 'login_google'])->where('created_at', '>=', now()->subDays(90))->count(),
                'first_activity_at' => Interaction::where('user_id', $user->id)->oldest()->value('created_at'),
                'last_activity_at' => $last?->created_at,
                'last_login_at' => $logins->first()?->created_at,
                'establishments' => $user->establishments->count(),
                'applications' => $user->applications->count(),
            ],
            'activity_by_type' => $byType,
            'application_usage' => $appUsage,
            'security' => [
                'ips' => $ips->map(fn ($count, $ip) => ['value' => $ip, 'count' => $count])->values(),
                'locations' => $locations->map(fn ($count, $value) => ['value' => $value, 'count' => $count])->values(),
                'devices' => $devices->map(fn ($count, $value) => ['value' => $value, 'count' => $count])->values(),
                'alerts' => $securityAlerts,
            ],
            'resources' => $this->userResources($user),
            'timeline' => $all->take(200)->map(fn ($item) => $this->interactionPayload($item))->values(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'user_name' => ['required', 'string', 'max:100', 'unique:users,user_name'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
        ]);
        $data['email'] = strtolower($data['email']);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        $this->audit($request, 'user.created', $user, null, $user->toArray());
        return response()->json(['user' => $user->load('profile:id,name')], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $user->toArray();
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'user_name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'user_name')->ignore($user->id)],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'max:2'],
        ]);
        if (isset($data['email'])) $data['email'] = strtolower($data['email']);
        $user->update($data);
        $this->audit($request, 'user.updated', $user, $before, $user->fresh()->toArray());
        return response()->json(['user' => $user->fresh()->load(['profile:id,name', 'applications:id,name,slug'])]);
    }

    public function destroyUser(Request $request, User $user): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_if($request->user()->is($user), 422, 'Você não pode excluir o próprio usuário administrativo.');
        $before = $user->toArray();
        $user->applications()->detach();
        $user->delete();
        $this->audit($request, 'user.deleted', null, $before, null, User::class, $before['id']);
        return response()->json(null, 204);
    }

    public function setUserAccess(Request $request, User $user, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'blocked', 'suspended'])],
            'role' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);
        $existing = $user->applications()->whereKey($application->id)->first();
        $before = $existing?->pivot?->toArray();
        $user->applications()->syncWithoutDetaching([
            $application->id => [
                'status' => $data['status'],
                'role' => $data['role'] ?? ($before['role'] ?? 'member'),
                'metadata' => json_encode($data['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'joined_at' => $before['joined_at'] ?? now(),
            ],
        ]);
        $after = $user->applications()->whereKey($application->id)->first()?->pivot?->toArray();
        $this->audit($request, 'access.updated', $user, $before, ['application_id' => $application->id, 'pivot' => $after]);
        return response()->json(['access' => $after]);
    }

    public function removeUserAccess(Request $request, User $user, Application $application): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $user->applications()->whereKey($application->id)->first()?->pivot?->toArray();
        $user->applications()->detach($application->id);
        $this->audit($request, 'access.removed', $user, $before, ['application_id' => $application->id]);
        return response()->json(null, 204);
    }

    public function profiles(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        return response()->json([
            'profiles' => Profile::query()->withCount('users')->orderBy('name')->get(),
            'permissions' => collect(config('permissions', []))->map(fn ($value, $key) => ['key' => $key] + $value)->values(),
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validateProfile($request);
        $profile = Profile::create($data);
        $this->audit($request, 'profile.created', $profile, null, $profile->toArray());
        return response()->json(['profile' => $profile], 201);
    }

    public function updateProfile(Request $request, Profile $profile): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $profile->toArray();
        $profile->update($this->validateProfile($request, $profile));
        $this->audit($request, 'profile.updated', $profile, $before, $profile->fresh()->toArray());
        return response()->json(['profile' => $profile->fresh()]);
    }

    public function establishments(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $query = Establishment::query()->with(['app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email']);
        if ($request->filled('app_id')) $query->forApplication($request->integer('app_id'));
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('fantasy', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"));
        }
        return response()->json(['establishments' => $query->latest('id')->limit(300)->get()]);
    }

    public function storeEstablishment(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validateEstablishment($request);
        $applicationIds = collect($data['app_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        unset($data['app_ids']);
        $data['app_id'] = (int) (($data['app_id'] ?? null) ?: $applicationIds->first());
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $establishment = DB::transaction(function () use ($data, $applicationIds) {
            $establishment = Establishment::create($data);
            $this->syncEstablishmentApplications($establishment, $applicationIds->all());
            return $establishment;
        });

        $this->audit($request, 'establishment.created', $establishment, null, $establishment->toArray());

        return response()->json([
            'establishment' => $establishment->load(['app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email']),
        ], 201);
    }

    public function updateEstablishment(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $establishment->toArray();
        $data = $this->validateEstablishment($request, $establishment);
        $applicationIds = array_key_exists('app_ids', $data)
            ? collect($data['app_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all()
            : $establishment->applications()->pluck('applications.id')->push($establishment->app_id)->filter()->unique()->values()->all();
        unset($data['app_ids']);
        if (! empty($applicationIds) && (! isset($data['app_id']) || ! in_array((int) $data['app_id'], $applicationIds, true))) {
            $data['app_id'] = $applicationIds[0];
        }
        $data['updated_by'] = $request->user()->id;

        DB::transaction(function () use ($establishment, $data, $applicationIds) {
            $establishment->update($data);
            $this->syncEstablishmentApplications($establishment->fresh(), $applicationIds);
        });
        $this->audit($request, 'establishment.updated', $establishment, $before, $establishment->fresh()->toArray());
        return response()->json(['establishment' => $establishment->fresh()->load(['app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email'])]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        return response()->json([
            'site' => array_replace_recursive($this->siteDefaults(), EcosystemSetting::query()->where('group', 'site')->get()->mapWithKeys(fn ($item) => [$item->key => $item->value])->all()),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['site' => ['required', 'array']]);
        foreach ($data['site'] as $key => $value) {
            abort_unless(in_array($key, array_keys($this->siteDefaults()), true), 422, "Configuração inválida: {$key}");
            $setting = EcosystemSetting::query()->firstOrNew(['group' => 'site', 'key' => $key]);
            $before = $setting->exists ? $setting->toArray() : null;
            $setting->fill(['value' => $value, 'is_public' => true, 'updated_by' => $request->user()->id])->save();
            $this->audit($request, 'site.updated', $setting, $before, $setting->fresh()->toArray());
        }
        return $this->settings($request);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        return response()->json(['logs' => EcosystemAuditLog::query()->with('user:id,first_name,last_name,email')->latest()->limit(300)->get()]);
    }

    private function interactionQuery()
    {
        return Interaction::query()->with([
            'user:id,first_name,last_name,user_name,email,avatar,profile_id',
            'user.profile:id,name',
            'application:id,name,slug,logo',
        ]);
    }

    private function interactionPayload(Interaction $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->interaction_type,
            'outcome' => $item->outcome,
            'severity' => $item->severity,
            'environment' => $item->environment,
            'request_id' => $item->request_id,
            'correlation_id' => $item->correlation_id,
            'parent_interaction_id' => $item->parent_interaction_id,
            'name' => $item->name,
            'entity_type' => $item->entity_type,
            'entity_id' => $item->entity_id,
            'route' => $item->route,
            'method' => $item->method,
            'session_key' => $item->session_key,
            'content' => $item->content,
            'created_at' => $item->created_at,
            'user' => $item->user,
            'application' => $item->application,
        ];
    }

    private function userResources(User $user): array
    {
        $resources = [
            'establishments' => $user->establishments->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->fantasy ?: $e->name,
                'application' => $e->app?->name,
                'app_id' => $e->app_id,
            ])->values(),
        ];

        foreach (['orders', 'items', 'appointments', 'productions', 'events'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                $resources[$table . '_count'] = DB::table($table)->where('user_id', $user->id)->count();
            }
        }

        return $resources;
    }

    private function deviceLabel(?string $agent): ?string
    {
        if (! $agent) return null;
        $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Safari/') ? 'Safari' : 'Outro navegador')));
        $device = str_contains($agent, 'Android') ? 'Android' : (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') ? 'iOS' : (str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Macintosh') ? 'macOS' : 'Outro dispositivo')));
        return "{$browser} · {$device}";
    }

    private function validateEstablishment(Request $request, ?Establishment $establishment = null): array
    {
        $creating = $establishment === null;

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('establishments', 'slug')->ignore($establishment?->id)],
            'cnpj' => ['nullable', 'string', 'max:30', Rule::unique('establishments', 'cnpj')->ignore($establishment?->id)],
            'type' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'website_url' => ['nullable', 'url', 'max:500'],
            'instagram_url' => ['nullable', 'url', 'max:500'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'app_ids' => [$creating ? 'required' : 'sometimes', 'required', 'array', 'min:1'],
            'app_ids.*' => ['integer', 'distinct', 'exists:applications,id'],
            'user_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', 'exists:users,id'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
        ]);
    }

    private function syncEstablishmentApplications(Establishment $establishment, array $applicationIds): void
    {
        $applicationIds = collect($applicationIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        abort_if($applicationIds->isEmpty(), 422, 'Selecione pelo menos uma aplicação.');

        $establishment->applications()->sync($applicationIds->mapWithKeys(fn ($id) => [
            $id => ['is_primary' => $id === (int) $establishment->app_id],
        ])->all());

        if (! $establishment->user_id) return;
        $user = User::find($establishment->user_id);
        if (! $user) return;

        foreach ($applicationIds as $applicationId) {
            $existing = $user->applications()->whereKey($applicationId)->first()?->pivot;
            $user->applications()->syncWithoutDetaching([
                $applicationId => [
                    'status' => 'active',
                    'role' => $existing?->role ?: 'owner',
                    'metadata' => $existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existing?->joined_at ?: now(),
                ],
            ]);
        }
    }

    private function validateProfile(Request $request, ?Profile $profile = null): array
    {
        $available = array_keys(config('permissions', []));
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('profiles', 'name')->ignore($profile?->id)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($available)],
        ]);
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        ), 403, 'Usuário sem permissão para administrar o ecossistema Peter Tecnet.');
    }

    private function audit(Request $request, string $action, $entity = null, $before = null, $after = null, ?string $entityType = null, ?int $entityId = null): void
    {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType ?: ($entity ? get_class($entity) : null),
            'entity_id' => $entityId ?: ($entity?->id),
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }

    private function siteDefaults(): array
    {
        return [
            'navigation' => [
                ['label' => 'Soluções', 'href' => '#solucoes'],
                ['label' => 'Processo', 'href' => '#processo'],
                ['label' => 'Tecnologia', 'href' => '#tecnologia'],
                ['label' => 'Projetos', 'href' => '#projetos'],
                ['label' => 'Iniciar projeto', 'href' => '#contato', 'highlight' => true],
            ],
            'hero' => [
                'eyebrow' => 'Tecnologia em movimento',
                'title' => 'Ideias. Código. Impacto.',
                'description' => 'Transformamos ideias em produtos digitais escaláveis. Aplicativos, plataformas e sistemas feitos para resolver problemas reais e gerar negócios.',
                'primary_label' => 'Começar um projeto',
                'primary_href' => '#contato',
                'secondary_label' => 'Explorar soluções',
                'secondary_href' => '#solucoes',
            ],
            'contact' => [
                'kicker' => 'Vamos construir algo relevante',
                'title' => 'Sua ideia pode ser o próximo grande produto.',
                'description' => 'Conte o que você quer transformar. Nós ajudamos a encontrar o melhor caminho tecnológico.',
                'email' => 'contato@petertecnet.com.br',
                'instagram' => 'https://www.instagram.com/petertecnet/',
            ],
        ];
    }
}
