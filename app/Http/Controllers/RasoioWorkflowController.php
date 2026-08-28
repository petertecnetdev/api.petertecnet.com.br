<?php

namespace App\Http\Controllers;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RasoioWorkflowController extends ApiController
{
    private const APP_ID = 1;
    private const TZ = 'America/Sao_Paulo';

    public function employerOrders(Request $request)
    {
        $userId = (int) $request->user()->id;

        $employers = Employer::query()
            ->with(['establishment:id,app_id,user_id,created_by,name,slug'])
            ->where('user_id', $userId)
            ->whereHas('establishment', fn ($q) => $q->where('app_id', self::APP_ID))
            ->get();

        $employerIds = $employers->pluck('id')->map(fn ($id) => (int) $id)->all();

        $orders = empty($employerIds)
            ? collect()
            : $this->orderedAppointmentsQuery()
                ->whereIn('attendant_id', $employerIds)
                ->get();

        return response()->json([
            'success' => true,
            'employers' => $employers,
            'employer' => $employers->first(),
            'orders' => $orders,
        ]);
    }

    public function establishmentOrders(Request $request, string $slug)
    {
        $actorId = (int) $request->user()->id;
        $establishment = Establishment::query()
            ->where('app_id', self::APP_ID)
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless(
            (int) $establishment->user_id === $actorId || (int) $establishment->created_by === $actorId,
            403,
            'Somente o responsável pela barbearia pode acessar esta agenda operacional.'
        );

        $employers = Employer::query()
            ->where('establishment_id', $establishment->id)
            ->with('user:id,first_name,last_name,user_name,avatar')
            ->orderBy('id')
            ->get();

        $orders = $this->orderedAppointmentsQuery()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->get();

        return response()->json([
            'success' => true,
            'establishment' => $establishment,
            'employers' => $employers,
            'orders' => $orders,
        ]);
    }

    public function transition(Request $request, int $id)
    {
        $data = $request->validate([
            'action' => 'required|string|in:accept,reject,cancel,complete,no_show',
            'reason' => 'nullable|string|max:1000',
        ]);

        $order = Order::query()
            ->where('app_id', self::APP_ID)
            ->where('type', 'appointment')
            ->findOrFail($id);

        $actorId = (int) $request->user()->id;
        $this->authorizeOrderManagement($order, $actorId);

        $current = strtolower((string) ($order->appointment_status ?: $order->status ?: 'pending'));
        $allowed = [
            'pending' => ['accept', 'reject', 'cancel'],
            'confirmed' => ['cancel', 'complete', 'no_show'],
        ];

        abort_unless(in_array($data['action'], $allowed[$current] ?? [], true), 422, 'Esta ação não é permitida para o estado atual do agendamento.');

        $start = $order->order_datetime ? Carbon::parse($order->order_datetime, self::TZ) : null;
        $end = $start ? $start->copy()->addMinutes(max(1, (int) ($order->total_duration ?: 30))) : null;
        $now = now(self::TZ);

        if (in_array($data['action'], ['complete', 'no_show'], true)) {
            abort_unless($end && $now->gte($end), 422, 'O atendimento só pode ser finalizado após o horário previsto.');
        }

        $next = [
            'accept' => 'confirmed',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            'complete' => 'completed',
            'no_show' => 'no_show',
        ][$data['action']];

        $changes = [
            'appointment_status' => $next,
            'status' => $next,
        ];

        if ($data['action'] === 'accept') {
            $changes['confirmed_by'] = $actorId;
        }

        if (in_array($data['action'], ['reject', 'cancel'], true)) {
            $changes['cancelled_by'] = $actorId;
            $changes['cancelled_reason'] = $data['reason'] ?? ($data['action'] === 'reject' ? 'Agendamento recusado.' : null);
        }

        if ($data['action'] === 'complete') {
            $changes['attended_at'] = $now;
        }

        $order->update($changes);

        return response()->json([
            'success' => true,
            'message' => 'Agendamento atualizado com sucesso.',
            'order' => $order->fresh()->load(['items.item', 'client', 'attendant.user']),
        ]);
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate([
            'attendant_id' => 'required|integer|exists:employers,id',
        ]);

        $order = Order::query()
            ->where('app_id', self::APP_ID)
            ->where('type', 'appointment')
            ->findOrFail($id);

        $actorId = (int) $request->user()->id;
        $establishment = Establishment::query()
            ->where('app_id', self::APP_ID)
            ->findOrFail($order->entity_id);

        abort_unless(
            (int) $establishment->user_id === $actorId || (int) $establishment->created_by === $actorId,
            403,
            'Somente o responsável pela barbearia pode redirecionar este agendamento.'
        );

        abort_unless(in_array($order->appointment_status, ['pending', 'confirmed'], true), 422, 'Este agendamento não pode mais ser redirecionado.');

        $employer = Employer::query()
            ->whereKey((int) $data['attendant_id'])
            ->where('establishment_id', $establishment->id)
            ->firstOrFail();

        $start = Carbon::parse($order->order_datetime, self::TZ);
        $end = $start->copy()->addMinutes(max(1, (int) ($order->total_duration ?: 30)));

        if (method_exists(Order::class, 'hasScheduleConflict')) {
            abort_if(
                Order::hasScheduleConflict($employer->id, $start, $end, $order->id),
                422,
                'O colaborador selecionado já possui outro agendamento nesse horário.'
            );
        }

        $order->update(['attendant_id' => $employer->id]);

        return response()->json([
            'success' => true,
            'message' => 'Agendamento direcionado para o colaborador.',
            'order' => $order->fresh()->load(['client', 'attendant.user']),
        ]);
    }

    public function userProfile(Request $request, string $userName)
    {
        $user = User::query()
            ->select(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'city', 'uf', 'about'])
            ->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')])
            ->where('user_name', $userName)
            ->firstOrFail();

        $employers = Employer::query()
            ->with(['establishment:id,app_id,user_id,created_by,name,slug,city,uf'])
            ->where('user_id', $user->id)
            ->whereHas('establishment', fn ($q) => $q->where('app_id', self::APP_ID))
            ->get();

        $ownedEstablishments = Establishment::query()
            ->where('app_id', self::APP_ID)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('created_by', $user->id);
            })
            ->get(['id', 'name', 'slug', 'city', 'uf']);

        $employerIds = $employers->pluck('id');
        $clientQuery = Order::query()->where('app_id', self::APP_ID)->where('type', 'appointment')->where('client_id', $user->id);
        $barberQuery = Order::query()->where('app_id', self::APP_ID)->where('type', 'appointment')->whereIn('attendant_id', $employerIds);

        $countStatuses = function ($query): array {
            $rows = (clone $query)
                ->select('appointment_status', DB::raw('COUNT(*) as total'))
                ->groupBy('appointment_status')
                ->pluck('total', 'appointment_status');

            return [
                'requested' => (int) $rows->sum(),
                'pending' => (int) ($rows['pending'] ?? 0),
                'confirmed' => (int) ($rows['confirmed'] ?? 0),
                'completed' => (int) (($rows['completed'] ?? 0) + ($rows['attended'] ?? 0)),
                'cancelled' => (int) (($rows['cancelled'] ?? 0) + ($rows['canceled'] ?? 0)),
                'rejected' => (int) ($rows['rejected'] ?? 0),
                'no_show' => (int) (($rows['no_show'] ?? 0) + ($rows['not_attended'] ?? 0)),
            ];
        };

        $roles = ['Cliente'];
        if ($employers->isNotEmpty()) {
            $roles[] = 'Barbeiro';
        }
        if ($ownedEstablishments->isNotEmpty() || $employers->contains(fn ($e) => in_array(strtolower((string) $e->role), ['gerente', 'manager'], true))) {
            $roles[] = 'Gerente';
        }

        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => array_values(array_unique($roles)),
            'client_metrics' => $countStatuses($clientQuery),
            'barber_metrics' => $employerIds->isEmpty() ? null : $countStatuses($barberQuery),
            'employments' => $employers,
            'managed_establishments' => $ownedEstablishments,
        ]);
    }

    private function orderedAppointmentsQuery()
    {
        return Order::query()
            ->where('app_id', self::APP_ID)
            ->where('type', 'appointment')
            ->with(['items.item', 'client', 'attendant.user'])
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN 0 WHEN appointment_status IN ('pending','confirmed') THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN order_datetime END ASC")
            ->orderBy('order_datetime', 'desc');
    }

    private function authorizeOrderManagement(Order $order, int $actorId): void
    {
        $isAttendant = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->where('user_id', $actorId)->exists()
            : false;

        $isManager = false;
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->where('app_id', self::APP_ID)
                ->find($order->entity_id);

            $isManager = $establishment && (
                (int) $establishment->user_id === $actorId ||
                (int) $establishment->created_by === $actorId
            );
        }

        abort_unless($isAttendant || $isManager, 403, 'Você não pode alterar este agendamento.');
    }
}
