<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEstablishmentRequest;
use App\Http\Requests\Api\V1\UpdateEstablishmentRequest;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EstablishmentController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('is_cancelled', false)
            ->where('is_published', true);

        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) {
            $query->where('is_approved', true);
        }

        if ($request->filled('city')) {
            $query->where('city', $request->string('city'));
        }

        if ($request->filled('uf')) {
            $query->where('uf', $request->string('uf'));
        }

        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('fantasy', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $pageSize = min(
            max((int) $request->query('per_page', config('platform.default_page_size', 20)), 1),
            config('platform.max_page_size', 100)
        );

        $result = $query->latest('id')->paginate($pageSize);
        $result->getCollection()->each->setAppends([]);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function show(string $slug): JsonResponse
    {
        $query = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where('is_published', true);

        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) {
            $query->where('is_approved', true);
        }

        $establishment = $query->firstOrFail();
        $establishment->setAppends([]);

        return response()->json(['success' => true, 'data' => $establishment]);
    }

    public function mine(Request $request): JsonResponse
    {
        $items = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->latest('id')
            ->get();

        $items->each->setAppends([]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(StoreEstablishmentRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $data['app_id'] = $this->context->id();
        $data['user_id'] = $user->id;
        $data['created_by'] = $user->id;
        $data['updated_by'] = $user->id;
        $data['slug'] = $this->uniqueSlug($data['fantasy'] ?? $data['name']);
        $data['is_featured'] = false;
        $data['is_approved'] = false;
        $data['is_cancelled'] = false;
        $data['is_published'] = false;

        $establishment = Establishment::create($data);

        $user->applications()->syncWithoutDetaching([
            $this->context->id() => [
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);

        $establishment->setAppends([]);

        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento criado com sucesso.',
            'data' => $establishment,
        ], 201);
    }

    public function update(UpdateEstablishmentRequest $request, int $establishment): JsonResponse
    {
        $model = $this->owned($request, $establishment);
        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $model->fill($data)->save();
        $model->setAppends([]);

        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento atualizado com sucesso.',
            'data' => $model->fresh(),
        ]);
    }

    public function destroy(Request $request, int $establishment): JsonResponse
    {
        $model = $this->owned($request, $establishment);
        $model->update([
            'is_cancelled' => true,
            'is_published' => false,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento cancelado com sucesso.',
        ]);
    }

    private function owned(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'establishment';
        $slug = $base;
        $counter = 2;

        while (Establishment::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
