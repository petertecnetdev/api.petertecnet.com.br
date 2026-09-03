<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use App\Services\EstablishmentDuplicateDetectionService;
use App\Support\TaxIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommercialOperationsController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $this->authorizeAny($request);

        return response()->json([
            'applications' => Application::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'url', 'logo']),
            'users' => User::query()->latest('id')->limit(300)->get(['id', 'first_name', 'last_name', 'user_name', 'email', 'avatar']),
            'establishments' => Establishment::query()->with([
                'app:id,name,slug',
                'applications:id,name,slug',
                'user:id,first_name,last_name,email,avatar',
                'creator:id,first_name,last_name,email',
                'updater:id,first_name,last_name,email',
            ])->latest('id')->limit(500)->get(),
            'items' => Item::query()->with([
                'establishment:id,name,fantasy,app_id',
                'establishment.applications:id,name,slug',
                'files' => fn ($files) => $files->where('status', 'active')->orderByDesc('is_primary')->orderByDesc('version'),
            ])->latest('id')->limit(1000)->get(),
        ]);
    }

    public function storeEstablishment(Request $request, EstablishmentDuplicateDetectionService $duplicates): JsonResponse
    {
        $this->authorizeCommercialPermission($request, 'establishment_manage', 'onboarding_manage');
        $data = $this->validateEstablishment($request);
        $match = $duplicates->detect($data, null, 1)->first();
        if (($match['score'] ?? 0) === 100) {
            return response()->json(['message' => 'Já existe um estabelecimento com o mesmo identificador fiscal.', 'duplicate' => $match], 422);
        }

        $applicationIds = collect($data['app_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        unset($data['app_ids']);
        $data['app_id'] = (int) (($data['app_id'] ?? null) ?: $applicationIds->first());
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $establishment = DB::transaction(function () use ($data, $applicationIds) {
            $establishment = Establishment::create($data);
            $this->syncApplications($establishment, $applicationIds->all());
            return $establishment;
        });

        return response()->json(['establishment' => $this->freshEstablishment($establishment)], 201);
    }

    public function updateEstablishment(Request $request, Establishment $establishment, EstablishmentDuplicateDetectionService $duplicates): JsonResponse
    {
        $this->authorizeCommercialPermission($request, 'establishment_manage', 'onboarding_manage');
        $data = $this->validateEstablishment($request, $establishment);
        $match = $duplicates->detect($data + $establishment->only(['name', 'fantasy', 'phone', 'email', 'country_code', 'tax_id', 'cnpj']), $establishment->id, 1)->first();
        if (($match['score'] ?? 0) === 100) {
            return response()->json(['message' => 'O identificador fiscal informado pertence a outro estabelecimento.', 'duplicate' => $match], 422);
        }

        $applicationIds = array_key_exists('app_ids', $data)
            ? collect($data['app_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
            : $establishment->applications()->pluck('applications.id')->push($establishment->app_id)->filter()->unique()->values()->all();
        unset($data['app_ids']);
        if ($applicationIds && (! isset($data['app_id']) || ! in_array((int) $data['app_id'], $applicationIds, true))) {
            $data['app_id'] = $applicationIds[0];
        }
        $data['updated_by'] = $request->user()->id;

        DB::transaction(function () use ($establishment, $data, $applicationIds) {
            $establishment->update($data);
            $this->syncApplications($establishment->fresh(), $applicationIds);
        });

        return response()->json(['establishment' => $this->freshEstablishment($establishment)]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $this->authorizeCommercialPermission($request, 'catalog_manage');
        $data = $this->validateItem($request);
        $establishment = Establishment::query()->with('applications:id')->findOrFail($data['entity_id']);
        $this->assertApplicationLinked($establishment, (int) $data['app_id']);

        $data['entity_name'] = 'establishment';
        $data['user_id'] = $establishment->user_id;
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $item = Item::create($data);

        return response()->json(['item' => $this->freshItem($item)], 201);
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $this->authorizeCommercialPermission($request, 'catalog_manage');
        $data = $this->validateItem($request, $item);
        $entityId = (int) ($data['entity_id'] ?? $item->entity_id);
        $appId = (int) ($data['app_id'] ?? $item->app_id);
        $establishment = Establishment::query()->with('applications:id')->findOrFail($entityId);
        $this->assertApplicationLinked($establishment, $appId);

        $data['entity_name'] = 'establishment';
        $data['user_id'] = $establishment->user_id;
        $data['updated_by'] = $request->user()->id;
        $item->update($data);

        return response()->json(['item' => $this->freshItem($item)]);
    }

    private function validateEstablishment(Request $request, ?Establishment $establishment = null): array
    {
        $creating = $establishment === null;
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:64'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'tax_id_type' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
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
            'app_ids' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'app_ids.*' => ['integer', 'distinct', 'exists:applications,id'],
            'user_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:users,id'],
            'is_published' => ['sometimes', 'boolean'],
            'is_approved' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_cancelled' => ['sometimes', 'boolean'],
        ]);

        $countryCode = strtoupper((string) ($data['country_code'] ?? $establishment?->country_code ?? 'BR'));
        $taxId = $data['tax_id'] ?? $data['cnpj'] ?? $establishment?->tax_id ?? $establishment?->cnpj;
        if ($taxId && ! TaxIdentifier::isValid($taxId, $countryCode)) {
            abort(422, 'O identificador fiscal informado não é válido para o país selecionado.');
        }

        if (array_key_exists('cnpj', $data) || array_key_exists('tax_id', $data) || $creating) {
            $data['country_code'] = $countryCode;
            $data['tax_id'] = TaxIdentifier::normalizeForCountry($taxId, $countryCode);
            $data['tax_id_type'] = TaxIdentifier::type($data['tax_id'], $countryCode);
            if ($countryCode === 'BR') $data['cnpj'] = $data['tax_id'];
        }

        return $data;
    }

    private function validateItem(Request $request, ?Item $item = null): array
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
        ]);
    }

    private function syncApplications(Establishment $establishment, array $applicationIds): void
    {
        $ids = collect($applicationIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        abort_if($ids->isEmpty(), 422, 'Selecione pelo menos uma aplicação.');
        $establishment->applications()->sync($ids->mapWithKeys(fn ($id) => [
            $id => ['is_primary' => $id === (int) $establishment->app_id],
        ])->all());

        if (! $establishment->user_id) return;
        $user = User::find($establishment->user_id);
        if (! $user) return;
        foreach ($ids as $appId) {
            $existing = $user->applications()->whereKey($appId)->first()?->pivot;
            $user->applications()->syncWithoutDetaching([
                $appId => [
                    'status' => 'active',
                    'role' => $existing?->role ?: 'owner',
                    'metadata' => $existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existing?->joined_at ?: now(),
                ],
            ]);
        }
    }

    private function assertApplicationLinked(Establishment $establishment, int $appId): void
    {
        $linked = $establishment->applications->pluck('id')->map(fn ($id) => (int) $id)->push((int) $establishment->app_id)->filter()->unique();
        abort_unless($linked->contains($appId), 422, 'A aplicação selecionada não está vinculada a este estabelecimento.');
    }

    private function freshEstablishment(Establishment $establishment): Establishment
    {
        return $establishment->fresh()->load([
            'app:id,name,slug', 'applications:id,name,slug', 'user:id,first_name,last_name,email,avatar',
            'creator:id,first_name,last_name,email', 'updater:id,first_name,last_name,email',
        ]);
    }

    private function freshItem(Item $item): Item
    {
        return $item->fresh()->load([
            'establishment:id,name,fantasy,app_id',
            'establishment.applications:id,name,slug',
            'files' => fn ($files) => $files->where('status', 'active')->orderByDesc('is_primary')->orderByDesc('version'),
        ]);
    }

    private function authorizeAny(Request $request): void
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('onboarding_manage')
            || $actor->hasPermission('establishment_manage')
            || $actor->hasPermission('catalog_manage')
            || $actor->hasPermission('ecosystem_manage')
        ), 403, 'Usuário sem permissão para acessar operações comerciais.');
    }

    private function authorizeCommercialPermission(Request $request, string ...$permissions): void
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('ecosystem_manage')
            || collect($permissions)->contains(fn ($permission) => $actor->hasPermission($permission))
        ), 403, 'Usuário sem permissão para esta operação comercial.');
    }
}
