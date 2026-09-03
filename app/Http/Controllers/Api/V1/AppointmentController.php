<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\SchedulingResource;
use App\Services\AppNotificationService;
use App\Services\Scheduling\SchedulingAuthorizationService;
use App\Services\Scheduling\SchedulingAvailabilityService;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SchedulingAvailabilityService $availability,
        private readonly SchedulingAuthorizationService $authorization,
        private readonly AppNotificationService $notifications
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id' => 'required|integer|exists:establishments,id',
            'items' => 'required|array|min:1|max:50',
            'items.*.item_id' => 'required|integer|distinct|exists:items,id',
            'items.*.quantity' => 'nullable|integer|min:1|max:100',
            'provider_id' => 'nullable|integer|exists:employers,id|required_without:resource_ids',
            'resource_ids' => 'nullable|array|max:20|required_without:provider_id',
            'resource_ids.*' => 'integer|distinct|exists:scheduling_resources,id',
            'scheduled_at' => 'required|date',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:5000',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $appId = $this->context->id();
        $establishment = $this->authorization->establishment($appId, (int) $data['establishment_id']);
        $items = $this->appointmentItems($data['items'], $establishment->id);
        $duration = $items->sum(fn (array $entry) => max(5, (int) ($entry['model']->duration ?: 30)) * $entry['quantity']);
        $scheduledAt = Carbon::parse($data['scheduled_at'], config('app.timezone', 'America/Sao_Paulo'))->startOfMinute();

        if ($scheduledAt->lte(now(config('app.timezone', 'America/Sao_Paulo'))->startOfMinute())) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['A data do agendamento deve ser futura.'],
            ]);
        }

        $providerId = isset($data['provider_id']) ? (int) $data['provider_id'] : null;
        $resourceIds = $data['resource_ids'] ?? [];

        $this->availability->assertSlotAvailable(
            $appId,
            $establishment->id,
            $scheduledAt,
            $duration,
            $providerId,
            $resourceIds
        );

        $resourceModels = $this->availability->resources($appId, $establishment->id, $resourceIds);
        $user = $request->user();

        $order = DB::transaction(function () use (
            $data,
            $appId,
            $establishment,
            $items,
            $duration,
            $scheduledAt,
            $providerId,
            $resourceModels,
            $user
        ) {
            $name = trim((string) ($data['customer_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));
            }
            if ($name === '') {
                $name = (string) ($user->user_name ?? 'Cliente');
            }

            $order = Order::create([
                'app_id' => $appId,
                'entity_name' => 'establishment',
                'entity_id' => $establishment->id,
                'order_number' => Order::nextOrderNumber($appId),
                'order_datetime' => $scheduledAt->format('Y-m-d H:i:s'),
                'created_by' => $user->id,
                'client_id' => $user->id,
                'attendant_id' => $providerId,
                'customer_name' => $name,
                'customer_phone' => $data['customer_phone'] ?? ($user->phone ?? null),
                'customer_email' => $data['customer_email'] ?? ($user->email ?? null),
                'origin' => 'app',
                'fulfillment' => 'service',
                'payment_status' => 'pending',
                'payment_method' => $data['payment_method'] ?? null,
                'type' => 'appointment',
                'status' => 'pending',
                'appointment_status' => 'pending',
                'total_price' => 0,
                'total_duration' => $duration,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->attachItems($items->map(fn (array $entry) => [
                'item_id' => $entry['model']->id,
                'quantity' => $entry['quantity'],
                'additions' => [],
                'removals' => [],
            ])->all());

            $order->update([
                'total_price' => (float) $order->items()->sum('subtotal'),
            ]);

            if ($resourceModels->isNotEmpty()) {
                $now = now();
                DB::table('order_scheduling_resource')->insert(
                    $resourceModels->map(fn (SchedulingResource $resource) => [
                        'order_id' => $order->id,
                        'scheduling_resource_id' => $resource->id,
                        'role' => 'required',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }

            return $order;
        });

        $fresh = $this->hydrateOrder($order->fresh());
        $this->notifyAppointmentCreated($fresh, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Agendamento criado com sucesso e aguardando confirmação.',
            'data' => $fresh,
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $orders = $this->appointmentsQuery()
            ->where('client_id', $request->user()->id)
            ->get()
            ->map(fn (Order $order) => $this->hydrateOrder($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function provider(Request $request): JsonResponse
    {
        $providerIds = Employer::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('establishment', fn ($query) => $query->where('app_id', $this->context->id()))
            ->pluck('id');

        $orders = $providerIds->isEmpty()
            ? collect()
            : $this->appointmentsQuery()->whereIn('attendant_id', $providerIds)->get();

        return response()->json([
            'success' => true,
            'data' => $orders->map(fn (Order $order) => $this->hydrateOrder($order)),
        ]);
    }

    public function establishment(Request $request, int $establishment): JsonResponse
    {
        $model = $this->authorization->managedEstablishment(
            $request,
            $this->context->id(),
            $establishment
        );

        $orders = $this->appointmentsQuery()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $model->id)
            ->get()
            ->map(fn (Order $order) => $this->hydrateOrder($order));

        return response()->json(['success' => true, 'data' => $orders]);
    }

    public function show(Request $request, int $appointment): JsonResponse
    {
        $order = $this->appointmentsQuery()->findOrFail($appointment);
        $this->authorizeVisibility($request, $order);

        return response()->json([
            'success' => true,
            'data' => $this->hydrateOrder($order),
        ]);
    }

    public function transition(Request $request, int $appointment): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|in:accept,reject,cancel,complete,no_show',
            'reason' => 'nullable|string|max:1000',
        ]);

        $order = $this->appointmentsQuery()->findOrFail($appointment);
        $this->authorizeManagement($request, $order);
        $current = strtolower((string) ($order->appointment_status ?: $order->status ?: 'pending'));
        $allowed = [
            'pending' => ['accept', 'reject', 'cancel'],
            'confirmed' => ['cancel', 'complete', 'no_show'],
        ];

        abort_unless(in_array($data['action'], $allowed[$current] ?? [], true), 422, 'Esta ação não é permitida para o estado atual do agendamento.');

        $end = Carbon::parse($order->order_datetime, config('app.timezone', 'America/Sao_Paulo'))
            ->addMinutes(max(5, (int) ($order->total_duration ?: 30)));
        if (in_array($data['action'], ['complete', 'no_show'], true)) {
            abort_unless(now(config('app.timezone', 'America/Sao_Paulo'))->gte($end), 422, 'O atendimento só pode ser finalizado após o horário previsto.');
        }

        $next = [
            'accept' => 'confirmed',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            'complete' => 'completed',
            'no_show' => 'no_show',
        ][$data['action']];

        $changes = ['appointment_status' => $next, 'status' => $next];
        if ($data['action'] === 'accept') {
            $changes['confirmed_by'] = $request->user()->id;
        }
        if (in_array($data['action'], ['reject', 'cancel'], true)) {
            $changes['cancelled_by'] = $request->user()->id;
            $changes['cancelled_reason'] = $data['reason'] ?? ($data['action'] === 'reject' ? 'Agendamento recusado.' : null);
        }
        if ($data['action'] === 'complete') {
            $changes['attended_at'] = now();
        }

        $order->update($changes);
        $fresh = $this->hydrateOrder($order->fresh());
        $this->notifyAppointmentUpdated($fresh, $next, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Agendamento atualizado com sucesso.',
            'data' => $fresh,
        ]);
    }

    public function assign(Request $request, int $appointment): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => 'nullable|integer|exists:employers,id|required_without:resource_ids',
            'resource_ids' => 'nullable|array|max:20|required_without:provider_id',
            'resource_ids.*' => 'integer|distinct|exists:scheduling_resources,id',
        ]);

        $order = $this->appointmentsQuery()->findOrFail($appointment);
        abort_unless($order->entity_name === 'establishment', 422, 'O agendamento não pertence a um estabelecimento gerenciável.');
        $establishment = $this->authorization->managedEstablishment(
            $request,
            $this->context->id(),
            (int) $order->entity_id
        );
        abort_unless(in_array($order->appointment_status, ['pending', 'confirmed'], true), 422, 'Este agendamento não pode mais ser redirecionado.');

        $providerId = isset($data['provider_id']) ? (int) $data['provider_id'] : null;
        $resourceIds = $data['resource_ids'] ?? [];
        $start = Carbon::parse($order->order_datetime, config('app.timezone', 'America/Sao_Paulo'));

        $this->availability->assertSlotAvailable(
            $this->context->id(),
            $establishment->id,
            $start,
            max(5, (int) $order->total_duration),
            $providerId,
            $resourceIds,
            $order->id
        );
        $resources = $this->availability->resources($this->context->id(), $establishment->id, $resourceIds);

        DB::transaction(function () use ($order, $providerId, $resources) {
            $order->update(['attendant_id' => $providerId]);
            DB::table('order_scheduling_resource')->where('order_id', $order->id)->delete();

            if ($resources->isNotEmpty()) {
                $now = now();
                DB::table('order_scheduling_resource')->insert(
                    $resources->map(fn (SchedulingResource $resource) => [
                        'order_id' => $order->id,
                        'scheduling_resource_id' => $resource->id,
                        'role' => 'required',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }
        });

        $fresh = $this->hydrateOrder($order->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Profissionais e recursos do agendamento atualizados com sucesso.',
            'data' => $fresh,
        ]);
    }

    private function appointmentItems(array $requestedItems, int $establishmentId)
    {
        $ids = collect($requestedItems)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values();
        $models = Item::query()
            ->whereIn('id', $ids)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishmentId)
            ->where('status', true)
            ->get()
            ->keyBy('id');

        if ($models->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => ['Um ou mais serviços não pertencem ao estabelecimento e aplicativo informados.'],
            ]);
        }

        return collect($requestedItems)->map(fn (array $entry) => [
            'model' => $models[(int) $entry['item_id']],
            'quantity' => (int) ($entry['quantity'] ?? 1),
        ]);
    }

    private function appointmentsQuery()
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->with(['items.item', 'items.modifiers.modifier', 'client', 'creator', 'attendant.user'])
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN 0 WHEN appointment_status IN ('pending','confirmed') THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN order_datetime END ASC")
            ->orderBy('order_datetime', 'desc');
    }

    private function hydrateOrder(Order $order): Order
    {
        $order->loadMissing(['items.item', 'items.modifiers.modifier', 'client', 'creator', 'attendant.user']);
        $resources = SchedulingResource::query()
            ->whereIn('id', DB::table('order_scheduling_resource')
                ->select('scheduling_resource_id')
                ->where('order_id', $order->id))
            ->with('employer.user:id,user_name,first_name,last_name,avatar')
            ->get();
        $order->setAttribute('scheduling_resources', $resources);

        return $order;
    }

    private function authorizeVisibility(Request $request, Order $order): void
    {
        $actorId = (int) $request->user()->id;
        $isClient = (int) $order->client_id === $actorId;
        $isCreator = (int) $order->created_by === $actorId;
        $isProvider = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->where('user_id', $actorId)->exists()
            : false;
        $isResourceProfessional = SchedulingResource::query()
            ->whereIn('id', DB::table('order_scheduling_resource')->select('scheduling_resource_id')->where('order_id', $order->id))
            ->whereHas('employer', fn ($query) => $query->where('user_id', $actorId))
            ->exists();

        $isManager = false;
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->whereKey($order->entity_id)
                ->where('app_id', $this->context->id())
                ->first();
            $isManager = $establishment ? $this->authorization->canManage($establishment, $actorId) : false;
        }

        abort_unless($isClient || $isCreator || $isProvider || $isResourceProfessional || $isManager, 403, 'Você não pode visualizar este agendamento.');
    }

    private function authorizeManagement(Request $request, Order $order): void
    {
        $actorId = (int) $request->user()->id;
        $isProvider = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->where('user_id', $actorId)->exists()
            : false;
        $isManager = false;

        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->whereKey($order->entity_id)
                ->where('app_id', $this->context->id())
                ->first();
            $isManager = $establishment ? $this->authorization->canManage($establishment, $actorId) : false;
        }

        abort_unless($isProvider || $isManager, 403, 'Você não pode alterar este agendamento.');
    }

    private function stakeholderUserIds(Order $order): array
    {
        $ids = [];
        if ($order->client_id) {
            $ids[] = (int) $order->client_id;
        }
        if ($order->attendant_id) {
            $providerUserId = Employer::query()->whereKey($order->attendant_id)->value('user_id');
            if ($providerUserId) {
                $ids[] = (int) $providerUserId;
            }
        }
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->whereKey($order->entity_id)
                ->where('app_id', $this->context->id())
                ->first();
            if ($establishment) {
                if ($establishment->user_id) {
                    $ids[] = (int) $establishment->user_id;
                }
                if ($establishment->created_by) {
                    $ids[] = (int) $establishment->created_by;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function notifyAppointmentCreated(Order $order, int $actorId): void
    {
        $this->notifications->sendToUsers(
            $this->context->id(),
            $this->stakeholderUserIds($order),
            [
                'type' => 'appointment.created',
                'title' => 'Novo agendamento',
                'message' => 'Um novo agendamento foi criado e aguarda confirmação.',
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'reference_url' => '/order/view/' . $order->id,
                'data' => ['status' => 'pending', 'order_number' => $order->order_number],
            ],
            $actorId
        );
    }

    private function notifyAppointmentUpdated(Order $order, string $status, int $actorId): void
    {
        $this->notifications->sendToUsers(
            $this->context->id(),
            $this->stakeholderUserIds($order),
            [
                'type' => 'appointment.updated',
                'title' => 'Agendamento atualizado',
                'message' => 'O status do agendamento foi atualizado.',
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'reference_url' => '/order/view/' . $order->id,
                'data' => ['status' => $status, 'order_number' => $order->order_number],
            ],
            $actorId
        );
    }
}
