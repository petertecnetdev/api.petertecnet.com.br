<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organizations\Services\EstablishmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEstablishmentRequest;
use App\Http\Requests\Api\V1\UpdateEstablishmentRequest;
use App\Http\Resources\Api\V1\EstablishmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentController extends Controller
{
    public function __construct(private readonly EstablishmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->service->paginatePublic($request);
        $result->setCollection($result->getCollection()->map(fn ($model) => (new EstablishmentResource($model))->resolve($request)));
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        return response()->json(['success' => true, 'data' => (new EstablishmentResource($this->service->publicBySlug($slug)))->resolve($request)]);
    }

    public function mine(Request $request): JsonResponse
    {
        $items = $this->service->mine($request->user()->id);
        return response()->json(['success' => true, 'data' => $items->map(fn ($model) => (new EstablishmentResource($model))->resolve($request))->values()]);
    }

    public function store(StoreEstablishmentRequest $request): JsonResponse
    {
        $model = $this->service->create($request->validated(), $request->user());
        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento criado com sucesso.',
            'data' => (new EstablishmentResource($model))->resolve($request),
        ], 201);
    }

    public function update(UpdateEstablishmentRequest $request, int $establishment): JsonResponse
    {
        $model = $this->service->update($establishment, $request->validated(), $request->user());
        return response()->json([
            'success' => true,
            'message' => 'Estabelecimento atualizado com sucesso.',
            'data' => (new EstablishmentResource($model))->resolve($request),
        ]);
    }

    public function destroy(Request $request, int $establishment): JsonResponse
    {
        $this->service->cancel($establishment, $request->user());
        return response()->json(['success' => true, 'message' => 'Estabelecimento cancelado com sucesso.']);
    }
}
