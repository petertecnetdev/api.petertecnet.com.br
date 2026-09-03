<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreEmployerRequest;
use App\Http\Requests\Api\V1\SyncEmployerItemsRequest;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\SchedulingResource;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployerController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function index(Request $request, int $establishment): JsonResponse
    {
        $owned = $this->ownedEstablishment($request, $establishment);

        $employers = Employer::query()
            ->with('user:id,user_name,first_name,last_name,email,phone,avatar')
            ->where('establishment_id', $owned->id)
            ->latest('id')
            ->get();

        $employers->each->setAppends([]);

        return response()->json(['success' => true, 'data' => $employers]);
    }

    public function store(StoreEmployerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $establishment = $this->ownedEstablishment($request, (int) $data['establishment_id']);

        $employer = DB::transaction(function () use ($request, $data, $establishment) {
            $employer = Employer::firstOrNew([
                'user_id' => (int) $data['user_id'],
                'establishment_id' => $establishment->id,
            ]);

            $employer->role = $data['role'] ?? $employer->role;
            $employer->permissions = $data['permissions'] ?? $employer->permissions;
            $employer->created_by = $employer->exists ? $employer->created_by : $request->user()->id;
            $employer->updated_by = $request->user()->id;
            $employer->save();
            $employer->load('user:id,user_name,first_name,last_name,email,phone,avatar')->setAppends([]);

            $this->syncProfessionalResource($employer, $request->user()->id);

            return $employer;
        });

        return response()->json([
            'success' => true,
            'message' => 'Vínculo profissional salvo com sucesso.',
            'data' => $employer,
        ], $employer->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $employer): JsonResponse
    {
        $model = $this->ownedEmployer($request, $employer);

        DB::transaction(function () use ($request, $model) {
            DB::table('employer_item')->where('employer_id', $model->id)->delete();

            SchedulingResource::query()
                ->where('app_id', $this->context->id())
                ->where('establishment_id', $model->establishment_id)
                ->where('employer_id', $model->id)
                ->update([
                    'employer_id' => null,
                    'is_active' => false,
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);

            $model->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Vínculo profissional removido com sucesso.',
        ]);
    }

    public function items(Request $request, int $employer): JsonResponse
    {
        $model = $this->ownedEmployer($request, $employer);

        $itemIds = DB::table('employer_item')
            ->where('employer_id', $model->id)
            ->pluck('item_id');

        $items = Item::query()
            ->with('files')
            ->whereIn('id', $itemIds)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $model->establishment_id)
            ->get();

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function syncItems(SyncEmployerItemsRequest $request, int $employer): JsonResponse
    {
        $model = $this->ownedEmployer($request, $employer);
        $requestedIds = collect($request->validated('item_ids'))->map(fn ($id) => (int) $id)->unique()->values();

        $validIds = Item::query()
            ->whereIn('id', $requestedIds)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $model->establishment_id)
            ->pluck('id');

        if ($validIds->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'item_ids' => ['Um ou mais itens não pertencem ao mesmo estabelecimento e aplicativo do profissional.'],
            ]);
        }

        DB::transaction(function () use ($request, $model, $validIds) {
            DB::table('employer_item')->where('employer_id', $model->id)->delete();

            if ($validIds->isNotEmpty()) {
                $now = now();
                DB::table('employer_item')->insert(
                    $validIds->map(fn ($itemId) => [
                        'employer_id' => $model->id,
                        'item_id' => $itemId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }

            $resource = $this->syncProfessionalResource($model, $request->user()->id);
            $resource->items()->sync($validIds->all());
        });

        return response()->json([
            'success' => true,
            'message' => 'Itens do profissional atualizados com sucesso.',
            'data' => ['item_ids' => $validIds->values()],
        ]);
    }

    public function metrics(Request $request, int $employer): JsonResponse
    {
        $model = $this->ownedEmployer($request, $employer);

        return response()->json([
            'success' => true,
            'data' => $model->getMetricsAttribute(),
        ]);
    }

    private function syncProfessionalResource(Employer $employer, int $actorId): SchedulingResource
    {
        $employer->loadMissing('user:id,user_name,first_name,last_name,email');
        $user = $employer->user;
        $name = trim((string) ($user?->first_name ?? '') . ' ' . (string) ($user?->last_name ?? ''));
        $name = $name !== '' ? $name : ((string) ($user?->user_name ?? $user?->email ?? 'Profissional'));

        $resource = SchedulingResource::firstOrNew([
            'establishment_id' => $employer->establishment_id,
            'employer_id' => $employer->id,
        ]);
        $resource->app_id = $this->context->id();
        $resource->type = 'professional';
        $resource->name = $name;
        $resource->capacity = 1;
        $resource->is_active = true;
        $resource->created_by = $resource->exists ? $resource->created_by : $actorId;
        $resource->updated_by = $actorId;
        $resource->save();

        return $resource;
    }

    private function ownedEstablishment(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function ownedEmployer(Request $request, int $id): Employer
    {
        return Employer::query()
            ->whereKey($id)
            ->whereIn('establishment_id', function ($query) use ($request) {
                $query->select('id')
                    ->from('establishments')
                    ->where('app_id', $this->context->id())
                    ->where('user_id', $request->user()->id)
                    ->where('is_cancelled', false);
            })
            ->firstOrFail();
    }
}
