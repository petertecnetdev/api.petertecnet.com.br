<?php

namespace App\Domain\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\EmployerSchedule;
use App\Models\Establishment;
use App\Models\Order;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentWorkflowController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly ApplicationContext $context) {}

    public function employerOrders(Request $request)
    {
        $userId = (int) $request->user()->id;
        $employers = Employer::query()
            ->with(['establishment:id,app_id,user_id,created_by,name,slug'])
            ->where('user_id', $userId)
            ->whereHas('establishment', function ($query) {
                $query
                    ->forApplication($this->context->id())
                    ->where('is_cancelled', false);
            })
            ->get();
        $employerIds = $employers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orders = empty($employerIds)
            ? collect()
            : $this->orderedAppointmentsQuery()->whereIn('attendant_id', $employerIds)->get();

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
            ->forApplication($this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->firstOrFail();

        abort_unless(
            $this->isEstablishmentManager($establishment, $actorId),
            403,
            'Somente a gestão do estabelecimento pode acessar esta agenda operacional.'
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

    public function orderDetail(Request $request, int $id)
    {
        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->with(['items.item', 'items.modifiers.modifier', 'client', 'creator', 'attendant.user', 'confirmedBy', 'cancelledBy'])
            ->findOrFail($id);

        $actorId = (int) $request->user()->id;
        $this->authorizeOrderVisibility($order, $actorId);
        $establishment = $order->entity_name === 'establishment'
            ? Establishment::query()
                ->forApplication($this->context->id())
                ->with(['employers.user'])
                ->find($order->entity_id)
            : null;

        $creator = $order->creator;
        $creatorRole = 'Usuário';
        $creatorRoleKey = 'user';

        if ($creator) {
            if ((int) $order->client_id === (int) $creator->id) {
                $creatorRole = 'Cliente';
                $creatorRoleKey = 'client';
            } elseif ($establishment) {
                $isOwner = (int) $establishment->user_id === (int) $creator->id || (int) $establishment->created_by === (int) $creator->id;
                $creatorEmployment = $establishment->employers->first(fn ($employment) => (int) $employment->user_id === (int) $creator->id);
                $employmentRole = strtolower(trim((string) ($creatorEmployment?->role ?? '')));
                $isManager = $isOwner || in_array($employmentRole, ['gerente', 'manager', 'gestor', 'administrador'], true);

                if ($isManager) {
                    $creatorRole = $isOwner ? 'Responsável do estabelecimento' : 'Gestor do estabelecimento';
                    $creatorRoleKey = 'manager';
                } elseif ($creatorEmployment) {
                    $creatorRole = 'Profissional';
                    $creatorRoleKey = 'professional';
                }
            }
        }

        $scheduledAt = $order->order_datetime ? Carbon::parse($order->order_datetime)->timezone(self::TZ) : null;
        $requestedAt = $order->created_at ? Carbon::parse($order->created_at)->timezone(self::TZ) : null;
        $now = now(self::TZ);

        return response()->json([
            'success' => true,
            'order' => $order,
            'establishment' => $establishment,
            'audit' => [
                'requested_at' => $requestedAt?->toIso8601String(),
                'scheduled_at' => $scheduledAt?->toIso8601String(),
                'seconds_until' => $scheduledAt ? $now->diffInSeconds($scheduledAt, false) : null,
                'created_by' => $creator ? [
                    'id' => $creator->id,
                    'first_name' => $creator->first_name,
                    'last_name' => $creator->last_name,
                    'user_name' => $creator->user_name,
                    'avatar' => $creator->avatar,
                    'role' => $creatorRole,
                    'role_key' => $creatorRoleKey,
                ] : null,
            ],
        ]);
    }

    public function transition(Request $request, int $id, AppNotificationService $notifications)
    {
        $data = $request->validate([
            'action' => 'required|string|in:accept,reject,cancel,complete,no_show',
            'reason' => 'nullable|string|max:1000',
        ]);
        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->findOrFail($id);
        $actorId = (int) $request->user()->id;
        $this->authorizeOrderManagement($order, $actorId);

        $current = strtolower((string) ($order->appointment_status ?: $order->status ?: 'pending'));
        $allowed = [
            'pending' => ['accept', 'reject', 'cancel'],
            'confirmed' => ['cancel', 'complete', 'no_show'],
        ];
        abort_unless(
            in_array($data['action'], $allowed[$current] ?? [], true),
            422,
            'Esta ação não é permitida para o estado atual do agendamento.'
        );

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
        $changes = ['appointment_status' => $next, 'status' => $next];
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
        $freshOrder = $order->fresh()->load(['items.item', 'client', 'attendant.user']);
        $labels = [
            'accept' => ['Agendamento confirmado', 'Seu agendamento foi confirmado.'],
            'reject' => ['Agendamento recusado', 'O agendamento foi recusado.'],
            'cancel' => ['Agendamento cancelado', 'O agendamento foi cancelado.'],
            'complete' => ['Atendimento concluído', 'O atendimento foi marcado como concluído.'],
            'no_show' => ['Não comparecimento registrado', 'O agendamento foi marcado como não comparecimento.'],
        ];
        [$title, $message] = $labels[$data['action']];
        $notifications->sendToUsers(
            $this->context->id(),
            $this->appointmentStakeholderUserIds($freshOrder),
            [
                'type' => 'appointment.updated',
                'title' => $title,
                'message' => $message,
                'reference_type' => 'order',
                'reference_id' => $freshOrder->id,
                'reference_url' => '/order/view/' . $freshOrder->id,
                'data' => ['status' => $next, 'order_number' => $freshOrder->order_number],
            ],
            $actorId
        );

        return response()->json([
            'success' => true,
            'message' => 'Agendamento atualizado com sucesso.',
            'order' => $freshOrder,
        ]);
    }

    public function assign(Request $request, int $id, AppNotificationService $notifications)
    {
        $data = $request->validate(['attendant_id' => 'required|integer|exists:employers,id']);
        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->findOrFail($id);
        $actorId = (int) $request->user()->id;
        $establishment = Establishment::query()
            ->forApplication($this->context->id())
            ->where('is_cancelled', false)
            ->findOrFail($order->entity_id);

        abort_unless(
            $this->isEstablishmentManager($establishment, $actorId),
            403,
            'Somente a gestão do estabelecimento pode redirecionar este agendamento.'
        );
        abort_unless(
            in_array($order->appointment_status, ['pending', 'confirmed'], true),
            422,
            'Este agendamento não pode mais ser redirecionado.'
        );

        $oldAttendantUserId = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->value('user_id')
            : null;
        $employer = Employer::query()
            ->whereKey((int) $data['attendant_id'])
            ->where('establishment_id', $establishment->id)
            ->with('user:id,first_name,last_name,user_name')
            ->firstOrFail();

        $start = Carbon::parse($order->order_datetime, self::TZ);
        $end = $start->copy()->addMinutes(max(1, (int) ($order->total_duration ?: 30)));
        $this->assertAttendantAvailability($employer, $start, $end);

        if (method_exists(Order::class, 'hasScheduleConflict')) {
            abort_if(
                Order::hasScheduleConflict($employer->id, $start, $end, $order->id),
                422,
                'O colaborador selecionado já possui outro agendamento nesse horário.'
            );
        }

        $order->update(['attendant_id' => $employer->id]);
        $freshOrder = $order->fresh()->load(['client', 'attendant.user']);
        $recipients = $this->appointmentStakeholderUserIds($freshOrder);
        if ($oldAttendantUserId) {
            $recipients[] = (int) $oldAttendantUserId;
        }

        $professionalName = trim(($employer->user?->first_name ?? '') . ' ' . ($employer->user?->last_name ?? '')) ?: 'novo profissional';
        $notifications->sendToUsers(
            $this->context->id(),
            $recipients,
            [
                'type' => 'appointment.assigned',
                'title' => 'Profissional do agendamento atualizado',
                'message' => 'O agendamento foi direcionado para ' . $professionalName . '.',
                'reference_type' => 'order',
                'reference_id' => $freshOrder->id,
                'reference_url' => '/order/view/' . $freshOrder->id,
                'data' => ['attendant_id' => $employer->id, 'order_number' => $freshOrder->order_number],
            ],
            $actorId
        );

        return response()->json([
            'success' => true,
            'message' => 'Agendamento direcionado para o colaborador.',
            'order' => $freshOrder,
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
            ->whereHas('establishment', function ($query) {
                $query
                    ->forApplication($this->context->id())
                    ->where('is_cancelled', false);
            })
            ->get();
        $ownedEstablishments = Establishment::query()
            ->forApplication($this->context->id())
            ->where('is_cancelled', false)
            ->where(function ($query) use ($user) {
                $query
                    ->where('user_id', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->get(['id', 'name', 'slug', 'city', 'uf']);

        $employerIds = $employers->pluck('id');
        $clientQuery = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->where('client_id', $user->id);
        $professionalQuery = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->whereIn('attendant_id', $employerIds);
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
            $roles[] = 'Profissional';
        }
        if (
            $ownedEstablishments->isNotEmpty()
            || $employers->contains(fn ($employer) => in_array(strtolower((string) $employer->role), ['gerente', 'manager'], true))
        ) {
            $roles[] = 'Gestor';
        }
        $professionalMetrics = $employerIds->isEmpty() ? null : $countStatuses($professionalQuery);

        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => array_values(array_unique($roles)),
            'client_metrics' => $countStatuses($clientQuery),
            'professional_metrics' => $professionalMetrics,
            // Legacy alias kept during frontend migration.
            'barber_metrics' => $professionalMetrics,
            'employments' => $employers,
            'managed_establishments' => $ownedEstablishments,
        ]);
    }

    private function assertAttendantAvailability(Employer $employer, Carbon $start, Carbon $end): void
    {
        $start = $start->copy()->setTimezone(self::TZ)->startOfMinute();
        $end = $end->copy()->setTimezone(self::TZ)->startOfMinute();
        $date = $start->toDateString();
        $dayOfWeek = strtolower($start->format('l'));

        $workSchedules = EmployerSchedule::query()
            ->where('employer_id', $employer->id)
            ->where('type', 'work')
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get(['start_time', 'end_time']);

        $fitsWorkSchedule = $workSchedules->contains(function ($schedule) use ($date, $start, $end) {
            $workStart = Carbon::parse($date . ' ' . $schedule->start_time, self::TZ)->startOfMinute();
            $workEnd = Carbon::parse($date . ' ' . $schedule->end_time, self::TZ)->startOfMinute();

            return $start->gte($workStart) && $end->lte($workEnd);
        });

        abort_unless(
            $fitsWorkSchedule,
            422,
            'O colaborador selecionado não atende durante todo o horário deste agendamento.'
        );

        $blockedSchedules = EmployerSchedule::query()
            ->where('employer_id', $employer->id)
            ->whereIn('type', ['break', 'holiday'])
            ->where('is_active', true)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('reserved_date', $date)
                    ->orWhere(function ($recurring) use ($dayOfWeek) {
                        $recurring->whereNull('reserved_date')
                            ->where('day_of_week', $dayOfWeek);
                    });
            })
            ->get(['type', 'start_time', 'end_time']);

        foreach ($blockedSchedules as $blockedSchedule) {
            if ($blockedSchedule->type === 'holiday' && ! $blockedSchedule->start_time && ! $blockedSchedule->end_time) {
                abort(422, 'O colaborador selecionado está indisponível nesta data.');
            }

            $blockedStart = Carbon::parse(
                $date . ' ' . ($blockedSchedule->start_time ?: '00:00'),
                self::TZ
            )->startOfMinute();
            $blockedEnd = Carbon::parse(
                $date . ' ' . ($blockedSchedule->end_time ?: '23:59'),
                self::TZ
            )->startOfMinute();

            if ($start->lt($blockedEnd) && $end->gt($blockedStart)) {
                abort(
                    422,
                    $blockedSchedule->type === 'holiday'
                        ? 'O colaborador selecionado está indisponível neste horário.'
                        : 'O colaborador selecionado possui uma pausa neste horário.'
                );
            }
        }
    }

    private function orderedAppointmentsQuery()
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->with(['items.item', 'client', 'attendant.user'])
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN 0 WHEN appointment_status IN ('pending','confirmed') THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN appointment_status IN ('pending','confirmed') AND order_datetime >= NOW() THEN order_datetime END ASC")
            ->orderBy('order_datetime', 'desc');
    }

    private function authorizeOrderVisibility(Order $order, int $actorId): void
    {
        $isClient = (int) $order->client_id === $actorId;
        $isCreator = (int) $order->created_by === $actorId;
        $isAttendant = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->where('user_id', $actorId)->exists()
            : false;
        $isManager = false;
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->forApplication($this->context->id())
                ->find($order->entity_id);
            $isManager = $establishment ? $this->isEstablishmentManager($establishment, $actorId) : false;
        }
        abort_unless(
            $isClient || $isCreator || $isAttendant || $isManager,
            403,
            'Você não pode visualizar este agendamento.'
        );
    }

    private function authorizeOrderManagement(Order $order, int $actorId): void
    {
        $isAttendant = $order->attendant_id
            ? Employer::query()->whereKey($order->attendant_id)->where('user_id', $actorId)->exists()
            : false;
        $isManager = false;
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->forApplication($this->context->id())
                ->find($order->entity_id);
            $isManager = $establishment ? $this->isEstablishmentManager($establishment, $actorId) : false;
        }
        abort_unless($isAttendant || $isManager, 403, 'Você não pode alterar este agendamento.');
    }

    private function isEstablishmentManager(Establishment $establishment, int $userId): bool
    {
        if ((int) $establishment->user_id === $userId || (int) $establishment->created_by === $userId) {
            return true;
        }

        return Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->whereIn('role', ['gerente', 'manager', 'gestor', 'administrador'])
            ->exists();
    }

    private function appointmentStakeholderUserIds(Order $order): array
    {
        $ids = [];
        if ($order->client_id) {
            $ids[] = (int) $order->client_id;
        }
        if ($order->attendant_id) {
            $attendantUserId = Employer::query()->whereKey($order->attendant_id)->value('user_id');
            if ($attendantUserId) {
                $ids[] = (int) $attendantUserId;
            }
        }
        if ($order->entity_name === 'establishment') {
            $establishment = Establishment::query()
                ->forApplication($this->context->id())
                ->find($order->entity_id);
            if ($establishment) {
                if ($establishment->user_id) {
                    $ids[] = (int) $establishment->user_id;
                }
                if ($establishment->created_by) {
                    $ids[] = (int) $establishment->created_by;
                }
                $managerIds = Employer::query()
                    ->where('establishment_id', $establishment->id)
                    ->whereIn('role', ['gerente', 'manager', 'gestor', 'administrador'])
                    ->pluck('user_id')
                    ->all();
                $ids = array_merge($ids, array_map('intval', $managerIds));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
