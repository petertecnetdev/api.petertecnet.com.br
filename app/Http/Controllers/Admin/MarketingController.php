<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $user = $this->authorizeMarketing($request);
        $applicationIds = $this->applicationIds($user);
        $applications = Application::query()->whereIn('id', $applicationIds)->orderBy('name')->get();

        return response()->json([
            'mode' => $user->hasProfile('Administrador') ? 'administrator' : 'marketing',
            'profile' => $user->profile?->name,
            'capabilities' => [
                'dashboard' => true,
                'activity' => $user->hasProfile('Administrador') || $user->hasPermission('marketing_activity_view'),
                'users' => $user->hasProfile('Administrador') || $user->hasPermission('marketing_user_view'),
                'invite_users' => $user->hasProfile('Administrador') || $user->hasPermission('marketing_user_invite'),
                'manage_users' => $user->hasProfile('Administrador'),
                'manage_applications' => $user->hasProfile('Administrador'),
                'manage_profiles' => $user->hasProfile('Administrador'),
                'manage_establishments' => $user->hasProfile('Administrador'),
                'manage_site' => $user->hasProfile('Administrador'),
                'view_audit' => $user->hasProfile('Administrador'),
            ],
            'applications' => $applications,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->authorizeMarketing($request, 'marketing_dashboard');
        $ids = $this->applicationIds($user);
        $now = now();

        $interaction = Interaction::query()->whereIn('app_id', $ids);
        $users = User::query()->whereHas('applications', fn ($query) => $query->whereIn('applications.id', $ids));
        $establishments = Establishment::query()->where(function ($query) use ($ids) {
            $query->whereIn('app_id', $ids)
                ->orWhereHas('applications', fn ($apps) => $apps->whereIn('applications.id', $ids));
        });

        $applications = Application::query()->whereIn('id', $ids)->orderBy('name')->get();
        $usage = Interaction::query()->whereIn('app_id', $ids)
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->selectRaw('app_id, COUNT(*) total, COUNT(DISTINCT user_id) unique_users')
            ->groupBy('app_id')->get()->keyBy('app_id');

        foreach ($applications as $application) {
            $application->users_count = DB::table('application_user')->where('application_id', $application->id)->count();
            $application->establishments_count = Establishment::query()->forApplication($application->id)->count();
            $application->activity_count_30d = (int) ($usage->get($application->id)?->total ?? 0);
            $application->active_users_30d = (int) ($usage->get($application->id)?->unique_users ?? 0);
        }

        $seriesStart = $now->copy()->subHours(23)->startOfHour();
        $seriesRows = (clone $interaction)->where('created_at', '>=', $seriesStart)->get(['interaction_type', 'outcome', 'created_at'])
            ->groupBy(fn ($item) => $item->created_at->format('Y-m-d H:00:00'));
        $series = collect(range(0, 23))->map(function ($offset) use ($seriesStart, $seriesRows) {
            $moment = $seriesStart->copy()->addHours($offset);
            $bucket = $seriesRows->get($moment->format('Y-m-d H:00:00'), collect());
            return ['timestamp' => $moment->toIso8601String(), 'label' => $moment->format('H:i'), 'total' => $bucket->count(), 'errors' => $bucket->where('outcome', 'error')->count()];
        })->values();

        $active30 = (clone $interaction)->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->subDays(30))->distinct()->count('user_id');

        return response()->json([
            'marketing_scope' => true,
            'summary' => [
                'applications' => count($ids),
                'active_applications' => $applications->where('is_active', true)->count(),
                'users' => (clone $users)->count(),
                'profiles' => 0,
                'establishments' => (clone $establishments)->count(),
                'published_establishments' => (clone $establishments)->where('is_published', true)->count(),
                'approved_establishments' => (clone $establishments)->where('is_approved', true)->count(),
                'access_links' => DB::table('application_user')->whereIn('application_id', $ids)->count(),
                'active_users_today' => (clone $interaction)->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->startOfDay())->distinct()->count('user_id'),
                'active_users_7d' => (clone $interaction)->whereNotNull('user_id')->where('created_at', '>=', $now->copy()->subDays(7))->distinct()->count('user_id'),
                'active_users_30d' => $active30,
                'inactive_users_30d' => max((clone $users)->count() - $active30, 0),
                'new_users_30d' => (clone $users)->where('users.created_at', '>=', $now->copy()->subDays(30))->count(),
                'interactions_today' => (clone $interaction)->where('created_at', '>=', $now->copy()->startOfDay())->count(),
                'interactions_30d' => (clone $interaction)->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            ],
            'applications' => $applications,
            'interaction_series' => $series,
            'activity_types' => (clone $interaction)->where('created_at', '>=', $now->copy()->subDays(30))
                ->selectRaw('interaction_type, COUNT(*) total')->groupBy('interaction_type')->orderByDesc('total')->limit(10)->get(),
            'recent_activity' => $this->interactionQuery($ids)->latest()->limit(16)->get()->map(fn ($item) => $this->payload($item)),
            'recent_users' => (clone $users)->with('profile:id,name')->latest('users.id')->limit(8)->get([
                'users.id', 'first_name', 'last_name', 'user_name', 'email', 'profile_id', 'users.created_at',
            ]),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $user = $this->authorizeMarketing($request, 'marketing_activity_view');
        $ids = $this->applicationIds($user);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'app_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:100'],
        ]);
        if (! empty($data['app_id'])) abort_unless(in_array((int) $data['app_id'], $ids, true), 403, 'Aplicação fora do escopo do colaborador.');

        $query = $this->interactionQuery($ids);
        if (! empty($data['user_id'])) $query->where('user_id', $data['user_id']);
        if (! empty($data['app_id'])) $query->where('app_id', $data['app_id']);
        if (! empty($data['type'])) $query->where('interaction_type', $data['type']);
        if (! empty($data['from'])) $query->where('created_at', '>=', $data['from']);
        if (! empty($data['to'])) $query->where('created_at', '<=', $data['to'].' 23:59:59');
        if (! empty($data['search'])) $query->where('name', 'like', '%'.$data['search'].'%');

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 40);
        $total = (clone $query)->count();
        $rows = $query->latest('id')->forPage($page, $perPage)->get();
        $lastPage = max((int) ceil($total / $perPage), 1);

        return response()->json([
            'summary' => ['total' => $total, 'users' => (clone $query)->whereNotNull('user_id')->distinct()->count('user_id'), 'applications' => count($ids)],
            'types' => Interaction::query()->whereIn('app_id', $ids)->distinct()->orderBy('interaction_type')->pluck('interaction_type')->filter()->values(),
            'activity' => $rows->map(fn ($item) => $this->payload($item)),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage, 'has_more' => $page < $lastPage, 'next_page' => $page < $lastPage ? $page + 1 : null],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $actor = $this->authorizeMarketing($request, 'marketing_user_view');
        $ids = $this->applicationIds($actor);
        $query = User::query()
            ->select(['users.id', 'first_name', 'last_name', 'user_name', 'email', 'avatar', 'profile_id', 'email_verified_at', 'users.created_at'])
            ->whereHas('applications', fn ($apps) => $apps->whereIn('applications.id', $ids))
            ->with(['profile:id,name', 'applications' => fn ($apps) => $apps->select('applications.id', 'name', 'slug', 'url', 'logo')->whereIn('applications.id', $ids)])
            ->withCount(['interactions' => fn ($interactions) => $interactions->whereIn('app_id', $ids), 'establishments']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($user) => $user->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('user_name', 'like', "%{$search}%"));
        }

        $users = $query->latest('users.id')->limit(300)->get();
        $last = Interaction::query()->whereIn('app_id', $ids)->whereIn('user_id', $users->pluck('id'))
            ->selectRaw('user_id, MAX(created_at) last_activity_at')->groupBy('user_id')->pluck('last_activity_at', 'user_id');
        $users->each(fn ($user) => $user->last_activity_at = $last[$user->id] ?? null);

        return response()->json(['users' => $users, 'marketing_scope' => true]);
    }

    public function userDetail(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeMarketing($request, 'marketing_user_view');
        $ids = $this->applicationIds($actor);
        abort_unless($user->applications()->whereIn('applications.id', $ids)->exists(), 404);

        $user->load(['profile:id,name', 'applications' => fn ($apps) => $apps->select('applications.id', 'name', 'slug', 'url', 'logo')->whereIn('applications.id', $ids)]);
        $safeUser = [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'user_name' => $user->user_name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'profile' => $user->profile,
            'applications' => $user->applications,
        ];
        $all = $this->interactionQuery($ids)->where('user_id', $user->id)->latest()->limit(200)->get();
        $logins = $all->whereIn('interaction_type', ['login', 'login_google']);

        return response()->json([
            'marketing_scope' => true,
            'user' => $safeUser,
            'summary' => [
                'total_interactions' => $all->count(),
                'interactions_7d' => $all->where('created_at', '>=', now()->subDays(7))->count(),
                'interactions_30d' => $all->where('created_at', '>=', now()->subDays(30))->count(),
                'logins_7d' => $logins->where('created_at', '>=', now()->subDays(7))->count(),
                'logins_30d' => $logins->where('created_at', '>=', now()->subDays(30))->count(),
                'logins_90d' => $logins->where('created_at', '>=', now()->subDays(90))->count(),
                'first_activity_at' => $all->last()?->created_at,
                'last_activity_at' => $all->first()?->created_at,
                'last_login_at' => $logins->first()?->created_at,
                'establishments' => 0,
                'applications' => $user->applications->count(),
            ],
            'activity_by_type' => $all->countBy('interaction_type')->map(fn ($total, $type) => ['interaction_type' => $type, 'total' => $total])->values(),
            'application_usage' => $all->whereNotNull('app_id')->groupBy('app_id')->map(function ($items, $appId) {
                return ['application' => $items->first()->application, 'total' => $items->count(), 'last_activity_at' => $items->first()->created_at];
            })->values(),
            'security' => ['ips' => [], 'locations' => [], 'devices' => [], 'alerts' => []],
            'resources' => ['establishments' => []],
            'timeline' => $all->map(fn ($item) => $this->payload($item)),
        ]);
    }

    private function authorizeMarketing(Request $request, ?string $permission = null): User
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission($permission ?: 'marketing_dashboard')), 403, 'Usuário sem acesso ao painel de marketing.');
        abort_if(! $user->hasProfile('Administrador') && $this->applicationIds($user) === [], 403, 'Nenhuma aplicação foi atribuída ao colaborador.');
        return $user;
    }

    private function applicationIds(User $user): array
    {
        if ($user->hasProfile('Administrador')) return Application::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $user->applications()->wherePivot('status', 'active')->pluck('applications.id')->map(fn ($id) => (int) $id)->all();
    }

    private function interactionQuery(array $ids): Builder
    {
        return Interaction::query()->whereIn('app_id', $ids)->with([
            'user:id,first_name,last_name,user_name,email,avatar,profile_id',
            'user.profile:id,name',
            'application:id,name,slug,logo',
        ]);
    }

    private function payload(Interaction $item): array
    {
        $content = is_array($item->content) ? $item->content : [];
        foreach (['ip', 'user_agent', 'parameters', 'input', 'query', 'exception'] as $sensitive) unset($content[$sensitive]);
        return [
            'id' => $item->id, 'type' => $item->interaction_type, 'outcome' => $item->outcome,
            'severity' => $item->severity, 'environment' => $item->environment, 'request_id' => $item->request_id,
            'correlation_id' => $item->correlation_id, 'parent_interaction_id' => $item->parent_interaction_id,
            'name' => $item->name, 'entity_type' => $item->entity_type, 'entity_id' => $item->entity_id,
            'route' => $item->route, 'method' => $item->method, 'session_key' => null, 'content' => $content,
            'created_at' => $item->created_at, 'user' => $item->user, 'application' => $item->application,
        ];
    }
}
