<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Support\CatalogAvailability;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class EcosystemCatalogController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogAvailability $availability
    ) {}

    public function companies(Request $request)
    {
        $appId = $this->context->id();
        $companies = Establishment::query()
            ->where('user_id', Auth::id())
            ->whereNull('source_establishment_id')
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])
            ->latest()
            ->get();

        $payload = $companies->map(function (Establishment $company) use ($appId) {
            $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id);
            $active = (int) $company->app_id === $appId || $applicationIds->contains($appId);

            return [
                'id' => $company->id,
                'name' => $company->name,
                'fantasy' => $company->fantasy,
                'slug' => $company->slug,
                'cnpj' => $company->cnpj,
                'phone' => $company->phone,
                'email' => $company->email,
                'description' => $company->description,
                'city' => $company->city,
                'uf' => $company->uf,
                'app_id' => $company->app_id,
                'application_ids' => $applicationIds->values(),
                'applications' => $company->applications,
                'files' => $company->files,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name,
                    'slug' => $company->app->slug,
                ] : null,
                'catalog_active' => $active,
                'catalog_establishment_id' => $active ? $company->id : null,
                'catalog_slug' => $active ? $company->slug : null,
                'catalog_files' => $active ? $company->files : [],
                'is_context_native' => (int) $company->app_id === $appId,
                'availability' => $this->availability->evaluate(
                    $company,
                    Auth::id(),
                    $this->approvalRequired(),
                    false
                ),
            ];
        })->values();

        return response()->json([
            'message' => 'Empresas do ecossistema listadas com sucesso.',
            'companies' => $payload,
        ]);
    }

    public function showCatalog(Request $request, string $identifier)
    {
        $appId = $this->context->id();
        $application = $this->context->application();

        $company = $this->catalogEstablishmentQuery($appId)
            ->when(
                is_numeric($identifier),
                fn (Builder $builder) => $builder->where('id', (int) $identifier),
                fn (Builder $builder) => $builder->where('slug', $identifier)
            )
            ->first();

        $availability = $this->availability->evaluate(
            $company,
            Auth::id(),
            $this->approvalRequired(),
            $request->boolean('preview')
        );

        if ($availability['http_status'] !== 200) {
            $this->registerRestrictedAttempt($company, $availability, 'catalog');
            return $this->availabilityResponse($availability);
        }

        $company->load([
            'files' => fn ($builder) => $builder
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->orderBy('position'),
            'app:id,name,slug',
            'applications:id,name,slug',
        ]);

        if (! $availability['preview']) {
            Interaction::registerView($company, Auth::user());
        }

        $items = Item::query()
            ->where('app_id', $appId)
            ->where('entity_name', 'establishment')
            ->where('entity_id', $company->id)
            ->where('status', true)
            ->with([
                'files' => fn ($builder) => $builder
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($builder) => $builder->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->get();

        $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();
        $establishment = array_merge($company->toArray(), [
            'application_ids' => $applicationIds,
            'catalog_active' => true,
            'catalog_establishment_id' => $company->id,
            'catalog_slug' => $company->slug,
            'is_context_native' => (int) $company->app_id === $appId,
        ]);
        $applicationPayload = $application->only(['id', 'name', 'slug']);

        return response()->json([
            'success' => true,
            'message' => $availability['preview']
                ? 'Pré-visualização privada do catálogo carregada com sucesso.'
                : 'Catálogo carregado com sucesso.',
            'availability' => $availability,
            'establishment' => $establishment,
            'items' => $items,
            'data' => [
                'application' => $applicationPayload,
                'availability' => $availability,
                'establishment' => $establishment,
                'items' => $items,
            ],
        ]);
    }

    public function showItem(Request $request, string $identifier)
    {
        $appId = $this->context->id();
        $application = $this->context->application();

        $item = Item::query()
            ->where('app_id', $appId)
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->when(
                is_numeric($identifier),
                fn (Builder $builder) => $builder->where('id', (int) $identifier),
                fn (Builder $builder) => $builder->where('slug', $identifier)
            )
            ->with([
                'files' => fn ($builder) => $builder
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($builder) => $builder->where('interaction_type', 'view')])
            ->first();

        if (! $item) {
            return $this->availabilityResponse(
                $this->availability->evaluate(null, Auth::id(), $this->approvalRequired(), false)
            );
        }

        $company = $this->catalogEstablishmentQuery($appId)
            ->find($item->entity_id);

        $availability = $this->availability->evaluate(
            $company,
            Auth::id(),
            $this->approvalRequired(),
            $request->boolean('preview')
        );

        if ($availability['http_status'] !== 200) {
            $this->registerRestrictedAttempt($company, $availability, 'catalog_item');
            return $this->availabilityResponse($availability);
        }

        $company->load([
            'files' => fn ($builder) => $builder
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->orderBy('position'),
            'app:id,name,slug',
            'applications:id,name,slug',
        ]);

        if (! $availability['preview']) {
            Interaction::registerView($item, Auth::user());
        }

        $otherItems = Item::query()
            ->where('app_id', $appId)
            ->where('entity_name', 'establishment')
            ->where('entity_id', $company->id)
            ->where('status', true)
            ->where('id', '!=', $item->id)
            ->with([
                'files' => fn ($builder) => $builder
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($builder) => $builder->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();
        $establishment = array_merge($company->toArray(), [
            'application_ids' => $applicationIds,
            'catalog_active' => true,
            'is_context_native' => (int) $company->app_id === $appId,
        ]);

        return response()->json([
            'success' => true,
            'message' => $availability['preview']
                ? 'Pré-visualização privada do item carregada com sucesso.'
                : 'Item carregado com sucesso.',
            'availability' => $availability,
            'item' => $item,
            'establishment' => $establishment,
            'other_items' => $otherItems,
            'data' => [
                'application' => $application->only(['id', 'name', 'slug']),
                'availability' => $availability,
                'item' => $item,
                'establishment' => $establishment,
                'other_items' => $otherItems,
            ],
        ]);
    }

    public function activate(Request $request, int $sourceId)
    {
        $appId = $this->context->id();
        $user = Auth::user();
        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->with(['files', 'applications:id,name,slug'])
            ->findOrFail($sourceId);

        DB::transaction(function () use ($company, $appId, $user) {
            $company->applications()->syncWithoutDetaching([
                $appId => ['is_primary' => (int) $company->app_id === $appId],
            ]);

            $existing = $user->applications()->whereKey($appId)->first()?->pivot;
            $user->applications()->syncWithoutDetaching([
                $appId => [
                    'status' => 'active',
                    'role' => $existing?->role ?: 'owner',
                    'metadata' => $existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existing?->joined_at ?: now(),
                ],
            ]);
        });

        return response()->json([
            'message' => 'Empresa vinculada ao contexto da aplicação com sucesso.',
            'establishment' => $company->fresh()->load(['files', 'applications:id,name,slug']),
        ]);
    }

    private function catalogEstablishmentQuery(int $appId): Builder
    {
        return Establishment::query()->where(function (Builder $builder) use ($appId) {
            $builder->where('app_id', $appId)
                ->orWhereHas('applications', fn (Builder $applicationQuery) => $applicationQuery->whereKey($appId));
        });
    }

    private function approvalRequired(): bool
    {
        return in_array(
            $this->context->slug(),
            config('platform.approval_required_apps', []),
            true
        );
    }

    private function availabilityResponse(array $availability)
    {
        return response()->json([
            'success' => false,
            'message' => $availability['message'],
            'availability' => $availability,
            'data' => [
                'availability' => $availability,
            ],
        ], $availability['http_status']);
    }

    private function registerRestrictedAttempt(
        ?Establishment $company,
        array $availability,
        string $resource
    ): void {
        if (! $company || ! in_array($availability['status'], ['restricted', 'unavailable'], true)) {
            return;
        }

        $ip = request()->ip();
        $userId = Auth::id();
        $recent = Interaction::query()
            ->where('entity_type', class_basename($company))
            ->where('entity_id', $company->id)
            ->where('interaction_type', 'restricted_access')
            ->where('created_at', '>=', now()->subMinute())
            ->where(function ($query) use ($userId, $ip) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                if ($ip) {
                    $query->orWhereJsonContains('content->ip', $ip);
                }
            })
            ->exists();

        if ($recent) {
            return;
        }

        Interaction::register('restricted_access', $company, Auth::user(), [
            'resource' => $resource,
            'availability_status' => $availability['status'],
            'availability_reason' => $availability['reason'],
        ], 'Tentativa de acesso a recurso restrito');
    }
}
