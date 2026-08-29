<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        return response()->json([
            'site' => array_replace_recursive($this->siteDefaults(), $settings->all()),
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        $applications = Application::query()
            ->withCount(['users', 'establishments', 'items'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'summary' => [
                'applications' => Application::count(),
                'active_applications' => Application::where('is_active', true)->count(),
                'users' => User::count(),
                'profiles' => Profile::count(),
                'establishments' => Establishment::count(),
                'published_establishments' => Establishment::where('is_published', true)->count(),
                'approved_establishments' => Establishment::where('is_approved', true)->count(),
                'access_links' => \DB::table('application_user')->count(),
            ],
            'applications' => $applications,
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

    public function users(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $query = User::query()->with(['profile:id,name', 'applications:id,name,slug']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%");
            });
        }
        return response()->json(['users' => $query->latest('id')->limit(300)->get()]);
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
        $query = Establishment::query()->with(['app:id,name,slug', 'user:id,first_name,last_name,email']);
        if ($request->filled('app_id')) $query->where('app_id', $request->integer('app_id'));
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('fantasy', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"));
        }
        return response()->json(['establishments' => $query->latest('id')->limit(300)->get()]);
    }

    public function updateEstablishment(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeAccess($request);
        $before = $establishment->toArray();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
        ]);
        $data['updated_by'] = $request->user()->id;
        $establishment->update($data);
        $this->audit($request, 'establishment.updated', $establishment, $before, $establishment->fresh()->toArray());
        return response()->json(['establishment' => $establishment->fresh()->load(['app:id,name,slug', 'user:id,first_name,last_name,email'])]);
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
