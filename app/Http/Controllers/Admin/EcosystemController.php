<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
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
            'outcome' => ['nullable', Rule::in(['success', 'denied', 'error'])],
            'severity' => ['nullable', Rule::in(['normal', 'attention', 'suspicious', 'critical'])],
            'environment' => ['nullable', 'string', 'max:50'],
            'method' => ['nullable', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])],
            'entity_type' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);

        $query = $this->interactionQuery();
        if (! empty($data['user_id'])) $query->where('user_id', $data['user_id']);
        if (! empty($data['app_id'])) $query->where('app_id', $data['app_id']);
        if (! empty($data['type'])) $query->where('interaction_type', $data['type']);
        if (! empty($data['outcome'])) $query->where('outcome', $data['outcome']);
        if (! empty($data['severity'])) $query->where('severity', $data['severity']);
        if (! empty($data['environment'])) $query->where('environment', $data['environment']);
        if (! empty($data['method'])) $query->where('method', $data['method']);
        if (! empty($data['entity_type'])) $query->where('entity_type', 'like', '%' . $data['entity_type'] . '%');
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

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'profile_id' => ['nullable', 'integer', 'exists:profiles,id'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'access_status' => ['nullable', Rule::in(['active', 'blocked', 'suspended', 'pending', 'none'])],
            'has_establishment' => ['nullable', Rule::in(['yes', 'no'])],
            'activity_from' => ['nullable', 'date'],
            'activity_to' => ['nullable', 'date', 'after_or_equal:activity_from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'name', 'email'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()
            ->with(['profile:id,name', 'applications:id,name,slug'])
            ->withCount(['interactions', 'establishments']);

        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        if (! empty($data['profile_id'])) {
            $query->where('profile_id', $data['profile_id']);
        }

        if (! empty($data['app_id'])) {
            $appId = (int) $data['app_id'];
            if (($data['access_status'] ?? null) === 'none') {
                $query->whereDoesntHave('applications', fn ($app) => $app->where('applications.id', $appId));
            } else {
                $query->whereHas('applications', function ($app) use ($appId, $data) {
                    $app->where('applications.id', $appId);
                    if (! empty($data['access_status'])) {
                        $app->where('application_user.status', $data['access_status']);
                    }
                });
            }
        } elseif (! empty($data['access_status']) && $data['access_status'] !== 'none') {
            $query->whereHas('applications', fn ($app) => $app->where('application_user.status', $data['access_status']));
        }

        if (($data['has_establishment'] ?? null) === 'yes') {
            $query->has('establishments');
        }
        if (($data['has_establishment'] ?? null) === 'no') {
            $query->doesntHave('establishments');
        }

        if (! empty($data['activity_from'])) {
            $query->whereHas('interactions', fn ($activity) => $activity->where('created_at', '>=', $data['activity_from']));
        }
        if (! empty($data['activity_to'])) {
            $until = date('Y-m-d 23:59:59', strtotime($data['activity_to']));
            $query->whereHas('interactions', fn ($activity) => $activity->where('created_at', '<=', $until));
        }

        switch ($data['sort'] ?? 'newest') {
            case 'oldest':
                $query->oldest('id');
                break;
            case 'name':
                $query->orderBy('first_name')->orderBy('last_name')->orderBy('id');
                break;
            case 'email':
                $query->orderBy('email')->orderBy('id');
                break;
            default:
                $query->latest('id');
                break;
        }

        // Preserve the legacy 300-user payload for existing Admin Center consumers
        // that do not opt into pagination yet. The Users Center always sends
        // per_page explicitly, so it receives the scalable paginated contract.
        $perPage = array_key_exists('per_page', $data) ? (int) $data['per_page'] : 300;
        $paginator = $query->paginate($perPage);
        $users = collect($paginator->items());
        $userIds = $users->pluck('id');
        $lastActivity = $userIds->isEmpty()
            ? collect()
            : Interaction::query()
                ->selectRaw('user_id, MAX(created_at) last_activity_at')
                ->whereIn('user_id', $userIds)
                ->groupBy('user_id')
                ->pluck('last_activity_at', 'user_id');

        $users->each(function ($user) use ($lastActivity) {
            $user->last_activity_at = $lastActivity[$user->id] ?? null;
        });

        return response()->json([
            'users' => $users->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more' => $paginator->hasMorePages(),
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
                'previous_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            ],
        ]);
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
        $query = Establishment::query()->with([
            'app:id,name,slug',
            'applications:id,name,slug',
            'user:id,first_name,last_name,email,avatar',
            'files' => fn ($files) => $files
                ->select('id', 'entity_id', 'entity_name', 'type', 'public_url', 'position')
                ->whereIn('type', ['logo', 'avatar', 'image', 'background'])
                ->orderByRaw("CASE type WHEN 'logo' THEN 1 WHEN 'avatar' THEN 2 WHEN 'image' THEN 3 ELSE 4 END")
                ->orderBy('position'),
        ]);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'approval' => ['nullable', Rule::in(['approved', 'pending'])],
            'publication' => ['nullable', Rule::in(['published', 'hidden'])],
            'featured' => ['nullable', Rule::in(['yes', 'no'])],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'type' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:150'],
        ]);
        if (! empty($data['app_id'])) $query->forApplication((int) $data['app_id']);
        if (! empty($data['user_id'])) $query->where('user_id', $data['user_id']);
        if (($data['approval'] ?? null) === 'approved') $query->where('is_approved', true);
        if (($data['approval'] ?? null) === 'pending') $query->where('is_approved', false);
        if (($data['publication'] ?? null) === 'published') $query->where('is_published', true);
        if (($data['publication'] ?? null) === 'hidden') $query->where('is_published', false);
        if (($data['featured'] ?? null) === 'yes') $query->where('is_featured', true);
        if (($data['featured'] ?? null) === 'no') $query->where('is_featured', false);
        if (! empty($data['city'])) $query->where('city', 'like', '%' . $data['city'] . '%');
        if (! empty($data['uf'])) $query->where('uf', strtoupper($data['uf']));
        if (! empty($data['type'])) $query->where('type', $data['type']);
        if (! empty($data['category'])) $query->where('category', 'like', '%' . $data['category'] . '%');
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('fantasy', 'like', "%{$search}%")
                ->orWhere('cnpj', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('id', $search));
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
        if (array_key_exists('user_id', $data)) {
            abort_if(
                (int) $data['user_id'] !== (int) $establishment->user_id,
                422,
                'Use a transferência de propriedade para alterar o proprietário do estabelecimento.'
            );
            unset($data['user_id']);
        }
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

    public function transferEstablishmentOwner(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Selecione o novo proprietário do estabelecimento.',
            'user_id.exists' => 'O usuário selecionado não existe.',
        ]);

        $newOwner = User::query()->findOrFail((int) $data['user_id']);
        $oldOwner = $establishment->user()->first();

        if ((int) $establishment->user_id === (int) $newOwner->id) {
            return response()->json([
                'message' => 'Este usuário já é o proprietário do estabelecimento.',
                'establishment' => $establishment->load(['app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email']),
                'previous_owner' => $oldOwner?->only(['id', 'first_name', 'last_name', 'email']),
                'new_owner' => $newOwner->only(['id', 'first_name', 'last_name', 'email']),
                'items_reassigned' => 0,
            ]);
        }

        $before = $establishment->toArray();
        $applicationIds = $establishment->applications()
            ->pluck('applications.id')
            ->push($establishment->app_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $itemsReassigned = DB::transaction(function () use ($request, $establishment, $newOwner, $applicationIds) {
            $establishment->forceFill([
                'user_id' => $newOwner->id,
                'updated_by' => $request->user()->id,
            ])->save();

            if (! empty($applicationIds)) {
                $this->syncEstablishmentApplications($establishment->fresh(), $applicationIds);
            }

            return Item::query()
                ->where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->update([
                    'user_id' => $newOwner->id,
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
        });

        $fresh = $establishment->fresh()->load(['app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email']);
        $this->audit(
            $request,
            'establishment.owner_transferred',
            $fresh,
            $before,
            $fresh->toArray()
        );

        return response()->json([
            'message' => 'Propriedade do estabelecimento transferida com sucesso.',
            'establishment' => $fresh,
            'previous_owner' => $oldOwner?->only(['id', 'first_name', 'last_name', 'email']),
            'new_owner' => $newOwner->only(['id', 'first_name', 'last_name', 'email']),
            'items_reassigned' => $itemsReassigned,
        ]);
    }

    public function destroyEstablishment(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);

        $before = $establishment->load([
            'applications:id,name,slug',
            'app:id,name,slug',
            'user:id,first_name,last_name,email',
        ])->toArray();

        DB::transaction(function () use ($request, $establishment, $before) {
            $establishment->forceFill([
                'is_published' => false,
                'is_approved' => false,
                'is_featured' => false,
                'is_cancelled' => true,
                'updated_by' => $request->user()->id,
            ])->save();

            $establishment->delete();

            $this->audit(
                $request,
                'establishment.deleted',
                null,
                $before,
                ['deleted_at' => $establishment->deleted_at?->toIso8601String()],
                Establishment::class,
                $establishment->id
            );
        });

        return response()->json(null, 204);
    }

    public function items(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['service', 'product', 'item', 'ticket'])],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
            'featured' => ['nullable', Rule::in(['yes', 'no'])],
            'category' => ['nullable', 'string', 'max:150'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $query = Item::query()
            ->with([
                'establishment:id,name,fantasy,app_id',
                'establishment.applications:id,name,slug',
                'files' => fn ($files) => $files->where('visibility', 'public')->where('status', 'active'),
            ])
            ->withCount('orderItems');

        if (! empty($data['app_id'])) $query->where('app_id', $data['app_id']);
        if (! empty($data['establishment_id'])) {
            $query->where('entity_name', 'establishment')->where('entity_id', $data['establishment_id']);
        }
        if (! empty($data['type'])) $query->where('type', $data['type']);
        if (($data['status'] ?? null) === 'active') $query->where('status', true);
        if (($data['status'] ?? null) === 'archived') $query->where('status', false);
        if (($data['featured'] ?? null) === 'yes') $query->where('is_featured', true);
        if (($data['featured'] ?? null) === 'no') $query->where('is_featured', false);
        if (! empty($data['category'])) $query->where('category', 'like', '%' . $data['category'] . '%');
        if (isset($data['min_price'])) $query->where('price', '>=', $data['min_price']);
        if (isset($data['max_price'])) $query->where('price', '<=', $data['max_price']);
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(fn ($item) => $item
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%"));
        }

        return response()->json(['items' => $query->latest('id')->limit(500)->get()]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $this->validateAdminItem($request);
        $establishment = Establishment::query()->forApplication((int) $data['app_id'])->findOrFail($data['entity_id']);
        $actor = $request->user();

        $item = Item::create($data + [
            'entity_name' => 'establishment',
            'user_id' => $establishment->user_id ?: $actor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->audit($request, 'item.created', $item, null, $item->toArray());
        return response()->json(['item' => $item->load(['establishment:id,name,fantasy,app_id', 'files'])], 201);
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $item->toArray();
        $data = $this->validateAdminItem($request, $item);
        $entityId = (int) ($data['entity_id'] ?? $item->entity_id);
        $appId = (int) ($data['app_id'] ?? $item->app_id);
        $establishment = Establishment::query()->forApplication($appId)->findOrFail($entityId);

        $item->update($data + [
            'entity_name' => 'establishment',
            'user_id' => $establishment->user_id ?: $item->user_id,
            'updated_by' => $request->user()->id,
        ]);

        $this->audit($request, 'item.updated', $item, $before, $item->fresh()->toArray());
        return response()->json(['item' => $item->fresh()->load(['establishment:id,name,fantasy,app_id', 'files'])]);
    }

    public function destroyItem(Request $request, Item $item): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $item->toArray();

        if ($item->orderItems()->exists()) {
            $item->forceFill(['status' => false, 'updated_by' => $request->user()->id])->save();
            $this->audit($request, 'item.archived', $item, $before, $item->fresh()->toArray());
            return response()->json(['message' => 'O item possui pedidos vinculados e foi arquivado para preservar o histórico.']);
        }

        $item->employers()->detach();
        $item->delete();
        $this->audit($request, 'item.deleted', null, $before, null, Item::class, $before['id']);
        return response()->json(null, 204);
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
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:150'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $query = EcosystemAuditLog::query()->with('user:id,first_name,last_name,email');
        if (! empty($data['user_id'])) $query->where('user_id', $data['user_id']);
        if (! empty($data['action'])) $query->where('action', $data['action']);
        if (! empty($data['entity_type'])) $query->where('entity_type', $data['entity_type']);
        if (! empty($data['from'])) $query->where('created_at', '>=', $data['from']);
        if (! empty($data['to'])) $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($data['to'])));
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(fn ($log) => $log->where('action', 'like', "%{$search}%")
                ->orWhere('entity_type', 'like', "%{$search}%")
                ->orWhere('entity_id', $search)
                ->orWhere('ip', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")));
        }
        return response()->json([
            'logs' => $query->latest()->limit(300)->get(),
            'actions' => EcosystemAuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->filter()->values(),
            'entity_types' => EcosystemAuditLog::query()->select('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type')->filter()->values(),
        ]);
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

    private function validateAdminItem(Request $request, ?Item $item = null): array
    {
        $creating = $item === null;
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'app_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', 'exists:applications,id'],
            'entity_id' => [$creating ? 'required' : 'sometimes', 'required', 'integer', 'exists:establishments,id'],
            'type' => [$creating ? 'required' : 'sometimes', 'required', Rule::in(['service', 'product', 'item', 'ticket'])],
            'price' => [$creating ? 'required' : 'sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:150'],
            'subcategory' => ['nullable', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:150'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'url', 'max:1000'],
            'status' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Informe o nome do item.',
            'app_id.required' => 'Selecione a aplicação do item.',
            'app_id.exists' => 'A aplicação selecionada não existe.',
            'entity_id.required' => 'Selecione a empresa do item.',
            'entity_id.exists' => 'A empresa selecionada não existe.',
            'type.required' => 'Selecione o tipo do item.',
            'type.in' => 'Selecione um tipo de item válido.',
            'price.required' => 'Informe o preço do item.',
            'price.numeric' => 'Informe um preço válido.',
            'image.url' => 'Informe uma URL válida para a imagem.',
        ]);
    }

    private function validateEstablishment(Request $request, ?Establishment $establishment = null): array
    {
        $creating = $establishment === null;
        $slugRules = ['nullable', 'string', 'max:255'];
        $cnpjRules = ['nullable', 'string', 'max:30'];

        $submittedSlug = trim((string) $request->input('slug', ''));
        $currentSlug = trim((string) ($establishment?->slug ?? ''));
        if ($creating || $submittedSlug !== $currentSlug) {
            $slugRules[] = Rule::unique('establishments', 'slug')
                ->ignore($establishment?->id)
                ->withoutTrashed();
        }

        $submittedCnpj = preg_replace('/\\D+/', '', (string) $request->input('cnpj', ''));
        $currentCnpj = preg_replace('/\\D+/', '', (string) ($establishment?->cnpj ?? ''));
        if ($creating || $submittedCnpj !== $currentCnpj) {
            $cnpjRules[] = Rule::unique('establishments', 'cnpj')
                ->ignore($establishment?->id)
                ->withoutTrashed();
        }

        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'slug' => $slugRules,
            'cnpj' => $cnpjRules,
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
        ], [
            'name.required' => 'Informe o nome do estabelecimento.',
            'slug.unique' => 'Este endereço interno (slug) já está sendo usado por outro estabelecimento.',
            'cnpj.unique' => 'Este CNPJ já está vinculado a outro estabelecimento.',
            'email.email' => 'Informe um e-mail válido.',
            'uf.size' => 'A UF deve conter exatamente duas letras.',
            'website_url.url' => 'Informe uma URL válida para o site, começando com http:// ou https://.',
            'instagram_url.url' => 'Informe uma URL válida para o Instagram, começando com http:// ou https://.',
            'app_id.exists' => 'A aplicação principal selecionada não existe.',
            'app_ids.required' => 'Selecione pelo menos uma aplicação.',
            'app_ids.min' => 'Selecione pelo menos uma aplicação.',
            'app_ids.*.distinct' => 'A mesma aplicação foi selecionada mais de uma vez.',
            'app_ids.*.exists' => 'Uma das aplicações selecionadas não existe.',
            'user_id.required' => 'Selecione o usuário responsável.',
            'user_id.exists' => 'O usuário responsável selecionado não existe.',
        ], [
            'name' => 'nome',
            'fantasy' => 'nome fantasia',
            'slug' => 'endereço interno',
            'cnpj' => 'CNPJ',
            'email' => 'e-mail',
            'uf' => 'UF',
            'app_id' => 'aplicação principal',
            'app_ids' => 'aplicações vinculadas',
            'user_id' => 'usuário responsável',
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
