<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Item;
use App\Models\SchedulingResource;
use App\Services\Scheduling\SchedulingAuthorizationService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchedulingResourceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SchedulingAuthorizationService $authorization
    ) {
    }

    public function index(Request $request, int $establishment): JsonResponse
    {
        $this->authorization->establishment($this->context->id(), $establishment);

        $resources = SchedulingResource::query()
            ->with([
                'employer.user:id,user_name,first_name,last_name,email,phone,avatar',
                'items:id,app_id,entity_name,entity_id,name,type,duration,price,status',
            ])
            ->where('app_id', $this->context->id())
            ->where('establishment_id', $establishment)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $resources]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $establishment = $this->authorization->managedEstablishment(
            $request,
            $this->context->id(),
            (int) $data['establishment_id']
        );

        $this->assertEmployer($data['employer_id'] ?? null, $establishment->id);
        $itemIds = $this->validatedItemIds($data['item_ids'] ?? [], $establishment->id);

        $resource = DB::transaction(function () use ($request, $data, $establishment, $itemIds) {
            $resource = SchedulingResource::create([
                'app_id' => $this->context->id(),
                'establishment_id' => $establishment->id,
                'employer_id' => $data['employer_id'] ?? null,
                'type' => $data['type'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'capacity' => $data['capacity'] ?? 1,
                'is_active' => $data['is_active'] ?? true,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $resource->items()->sync($itemIds);

            return $resource;
        });

        return response()->json([
            'success' => true,
            'message' => 'Recurso agendável criado com sucesso.',
            'data' => $resource->load(['employer.user', 'items']),
        ], 201);
    }

    public function update(Request $request, int $resource): JsonResponse
    {
        $model = $this->managedResource($request, $resource);
        $data = $request->validate($this->rules(true));

        $establishmentId = (int) ($data['establishment_id'] ?? $model->establishment_id);
        abort_unless($establishmentId === (int) $model->establishment_id, 422, 'O recurso não pode ser movido para outro estabelecimento.');

        $this->assertEmployer($data['employer_id'] ?? $model->employer_id, $model->establishment_id);
        $itemIds = array_key_exists('item_ids', $data)
            ? $this->validatedItemIds($data['item_ids'] ?? [], $model->establishment_id)
            : null;

        DB::transaction(function () use ($request, $model, $data, $itemIds) {
            $model->fill(collect($data)->only([
                'employer_id',
                'type',
                'name',
                'description',
                'capacity',
                'is_active',
                'metadata',
            ])->all());
            $model->updated_by = $request->user()->id;
            $model->save();

            if ($itemIds !== null) {
                $model->items()->sync($itemIds);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Recurso agendável atualizado com sucesso.',
            'data' => $model->fresh()->load(['employer.user', 'items']),
        ]);
    }

    public function destroy(Request $request, int $resource): JsonResponse
    {
        $model = $this->managedResource($request, $resource);

        abort_if(
            DB::table('order_scheduling_resource')
                ->where('scheduling_resource_id', $model->id)
                ->exists(),
            409,
            'Este recurso possui histórico de agendamentos e não pode ser excluído. Desative-o em vez disso.'
        );

        $model->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recurso agendável removido com sucesso.',
        ]);
    }

    public function schedules(Request $request, int $resource): JsonResponse
    {
        $model = $this->scopedResource($resource);
        $this->authorization->managedEstablishment($request, $this->context->id(), $model->establishment_id);

        return response()->json([
            'success' => true,
            'data' => $model->schedules()
                ->orderByRaw('reserved_date IS NULL DESC')
                ->orderBy('day_of_week')
                ->orderBy('reserved_date')
                ->orderBy('start_time')
                ->get(),
        ]);
    }

    public function syncSchedules(Request $request, int $resource): JsonResponse
    {
        $model = $this->managedResource($request, $resource);
        $data = $request->validate([
            'schedules' => 'present|array|max:100',
            'schedules.*.day_of_week' => ['nullable', 'string', Rule::in([
                'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            ])],
            'schedules.*.reserved_date' => 'nullable|date',
            'schedules.*.start_time' => 'nullable|date_format:H:i',
            'schedules.*.end_time' => 'nullable|date_format:H:i',
            'schedules.*.type' => ['required', 'string', Rule::in(['work', 'break', 'holiday', 'blocked'])],
            'schedules.*.is_active' => 'nullable|boolean',
            'schedules.*.metadata' => 'nullable|array',
        ]);

        foreach ($data['schedules'] as $index => $schedule) {
            $type = $schedule['type'];
            if ($type === 'work' && empty($schedule['day_of_week'])) {
                throw ValidationException::withMessages([
                    "schedules.$index.day_of_week" => ['Horários recorrentes de trabalho exigem o dia da semana.'],
                ]);
            }

            if (in_array($type, ['break', 'holiday', 'blocked'], true) && empty($schedule['reserved_date'])) {
                throw ValidationException::withMessages([
                    "schedules.$index.reserved_date" => ['Bloqueios e exceções exigem uma data.'],
                ]);
            }

            if ($type !== 'holiday' && (empty($schedule['start_time']) || empty($schedule['end_time']))) {
                throw ValidationException::withMessages([
                    "schedules.$index.start_time" => ['Informe o início e o fim do período.'],
                ]);
            }
        }

        DB::transaction(function () use ($model, $data) {
            $model->schedules()->delete();
            foreach ($data['schedules'] as $schedule) {
                $model->schedules()->create([
                    'day_of_week' => $schedule['day_of_week'] ?? null,
                    'reserved_date' => $schedule['reserved_date'] ?? null,
                    'start_time' => $schedule['start_time'] ?? null,
                    'end_time' => $schedule['end_time'] ?? null,
                    'type' => $schedule['type'],
                    'is_active' => $schedule['is_active'] ?? true,
                    'metadata' => $schedule['metadata'] ?? null,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Disponibilidade do recurso atualizada com sucesso.',
            'data' => $model->schedules()->get(),
        ]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'establishment_id' => [$required, 'integer', 'exists:establishments,id'],
            'employer_id' => 'nullable|integer|exists:employers,id',
            'type' => [$required, 'string', Rule::in(SchedulingResource::TYPES)],
            'name' => [$required, 'string', 'max:255'],
            'description' => 'nullable|string|max:5000',
            'capacity' => 'nullable|integer|min:1|max:10000',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
            'item_ids' => 'nullable|array|max:500',
            'item_ids.*' => 'integer|distinct|exists:items,id',
        ];
    }

    private function scopedResource(int $resource): SchedulingResource
    {
        return SchedulingResource::query()
            ->whereKey($resource)
            ->where('app_id', $this->context->id())
            ->firstOrFail();
    }

    private function managedResource(Request $request, int $resource): SchedulingResource
    {
        $model = $this->scopedResource($resource);
        $this->authorization->managedEstablishment($request, $this->context->id(), $model->establishment_id);

        return $model;
    }

    private function assertEmployer(?int $employerId, int $establishmentId): void
    {
        if (! $employerId) {
            return;
        }

        $exists = Employer::query()
            ->whereKey($employerId)
            ->where('establishment_id', $establishmentId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'employer_id' => ['O profissional deve pertencer ao mesmo estabelecimento do recurso.'],
            ]);
        }
    }

    private function validatedItemIds(array $itemIds, int $establishmentId): array
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $validIds = Item::query()
            ->whereIn('id', $ids)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishmentId)
            ->pluck('id');

        if ($validIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'item_ids' => ['Um ou mais serviços não pertencem ao mesmo estabelecimento e aplicativo do recurso.'],
            ]);
        }

        return $validIds->map(fn ($id) => (int) $id)->all();
    }
}
