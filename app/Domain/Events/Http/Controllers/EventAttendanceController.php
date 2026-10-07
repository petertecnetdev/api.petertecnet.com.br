<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EventAttendanceController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function publicSummary(string $slug): JsonResponse
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('kind', 'community')
            ->publiclyVisible()
            ->firstOrFail();

        return response()->json($this->summary($event));
    }

    public function show(Request $request, int $eventId): JsonResponse
    {
        $event = $this->participableEvent($eventId);
        $attendance = EventAttendance::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            ...$this->summary($event),
            'mine' => $attendance ? $this->attendancePayload($attendance) : null,
        ]);
    }

    public function update(Request $request, int $eventId): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:interested,going,cancelled',
        ]);

        $attendance = DB::transaction(function () use ($request, $eventId, $data) {
            $event = Event::query()
                ->where('app_id', $this->context->id())
                ->where('kind', 'community')
                ->lockForUpdate()
                ->findOrFail($eventId);

            $this->assertParticipable($event);
            abort_if($event->hasEnded(), 422, 'Este encontro já terminou.');

            $current = EventAttendance::query()
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            abort_if(
                $current?->status === EventAttendance::STATUS_ATTENDED,
                409,
                'Sua presença já foi registrada neste encontro.'
            );

            if ($data['status'] === EventAttendance::STATUS_GOING
                && ! in_array($current?->status, [EventAttendance::STATUS_GOING, EventAttendance::STATUS_ATTENDED], true)
                && $event->max_attendees) {
                $going = EventAttendance::query()
                    ->where('app_id', $this->context->id())
                    ->where('event_id', $event->id)
                    ->whereIn('status', [EventAttendance::STATUS_GOING, EventAttendance::STATUS_ATTENDED])
                    ->count();

                abort_if($going >= (int) $event->max_attendees, 409, 'Este encontro atingiu a capacidade máxima.');
            }

            $payload = [
                'status' => $data['status'],
                'updated_at' => now(),
            ];

            if ($data['status'] === EventAttendance::STATUS_CANCELLED) {
                $payload += [
                    'checked_in_at' => null,
                    'checkin_method' => null,
                    'checked_in_by' => null,
                    'distance_meters' => null,
                ];
            }

            if ($current) {
                $current->fill($payload)->save();
                return $current->fresh();
            }

            return EventAttendance::create([
                'app_id' => $this->context->id(),
                'event_id' => $event->id,
                'user_id' => $request->user()->id,
                ...$payload,
            ]);
        }, 3);

        $event = Event::query()->findOrFail($eventId);

        return response()->json([
            'message' => match ($attendance->status) {
                EventAttendance::STATUS_INTERESTED => 'Interesse registrado.',
                EventAttendance::STATUS_GOING => 'Presença confirmada. Nos vemos no encontro.',
                default => 'Participação cancelada.',
            },
            ...$this->summary($event),
            'mine' => $this->attendancePayload($attendance),
        ]);
    }

    public function selfCheckin(Request $request, int $eventId): JsonResponse
    {
        $data = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $attendance = DB::transaction(function () use ($request, $eventId, $data) {
            $event = Event::query()
                ->where('app_id', $this->context->id())
                ->where('kind', 'community')
                ->lockForUpdate()
                ->findOrFail($eventId);

            $this->assertParticipable($event);
            abort_unless($event->isHappeningNow(), 422, 'O check-in próprio fica disponível enquanto o encontro está acontecendo.');
            abort_unless($event->latitude !== null && $event->longitude !== null, 422, 'Este encontro não possui coordenadas para check-in por localização. Peça ao organizador para confirmar sua presença.');

            $attendance = EventAttendance::query()
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $attendance && in_array($attendance->status, [EventAttendance::STATUS_INTERESTED, EventAttendance::STATUS_GOING, EventAttendance::STATUS_ATTENDED], true),
                422,
                'Confirme participação antes de fazer check-in.'
            );

            if ($attendance->status === EventAttendance::STATUS_ATTENDED) {
                return $attendance;
            }

            $distance = $this->distanceMeters(
                (float) $data['latitude'],
                (float) $data['longitude'],
                (float) $event->latitude,
                (float) $event->longitude,
            );
            $radius = max(50, (int) ($event->attendance_radius_m ?: 250));

            abort_if($distance > $radius, 422, "Você precisa estar a até {$radius} m do local para confirmar presença.");

            $attendance->forceFill([
                'status' => EventAttendance::STATUS_ATTENDED,
                'checked_in_at' => now(),
                'checkin_method' => 'geolocation',
                'checked_in_by' => $request->user()->id,
                'distance_meters' => $distance,
            ])->save();

            return $attendance->fresh();
        }, 3);

        $event = Event::query()->findOrFail($eventId);

        return response()->json([
            'message' => 'Presença confirmada no local.',
            ...$this->summary($event),
            'mine' => $this->attendancePayload($attendance),
        ]);
    }

    public function manage(Request $request, int $eventId): JsonResponse
    {
        $event = $this->managedEvent($request, $eventId);
        $participants = EventAttendance::query()
            ->where('event_attendances.app_id', $this->context->id())
            ->where('event_attendances.event_id', $event->id)
            ->join('users as u', 'u.id', '=', 'event_attendances.user_id')
            ->select([
                'event_attendances.id',
                'event_attendances.user_id',
                'event_attendances.status',
                'event_attendances.checked_in_at',
                'event_attendances.checkin_method',
                'event_attendances.distance_meters',
                'event_attendances.created_at',
                'u.first_name',
                'u.last_name',
                'u.user_name',
                'u.avatar',
            ])
            ->orderByRaw("CASE event_attendances.status WHEN 'attended' THEN 1 WHEN 'going' THEN 2 WHEN 'interested' THEN 3 ELSE 4 END")
            ->orderBy('u.first_name')
            ->get();

        return response()->json([
            ...$this->summary($event, true),
            'participants' => $participants,
        ]);
    }

    public function organizerCheckin(Request $request, int $eventId, int $userId): JsonResponse
    {
        $attendance = DB::transaction(function () use ($request, $eventId, $userId) {
            $event = Event::query()
                ->where('app_id', $this->context->id())
                ->where('kind', 'community')
                ->lockForUpdate()
                ->findOrFail($eventId);

            $this->assertCanManage($request, $event);
            abort_if(! $event->start_date || now()->lt($event->start_date), 422, 'A presença só pode ser confirmada depois do início do encontro.');
            abort_if($event->end_date && now()->gt($event->end_date->copy()->addDay()), 422, 'A janela para confirmação manual deste encontro foi encerrada.');

            $attendance = EventAttendance::query()
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $attendance && in_array($attendance->status, [EventAttendance::STATUS_INTERESTED, EventAttendance::STATUS_GOING, EventAttendance::STATUS_ATTENDED], true),
                404,
                'Participante não encontrado neste encontro.'
            );

            if ($attendance->status !== EventAttendance::STATUS_ATTENDED) {
                $attendance->forceFill([
                    'status' => EventAttendance::STATUS_ATTENDED,
                    'checked_in_at' => now(),
                    'checkin_method' => 'organizer',
                    'checked_in_by' => $request->user()->id,
                    'distance_meters' => null,
                ])->save();
            }

            return $attendance->fresh();
        }, 3);

        return response()->json([
            'message' => 'Presença confirmada pelo organizador.',
            'attendance' => $this->attendancePayload($attendance),
        ]);
    }

    private function participableEvent(int $eventId): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('kind', 'community')
            ->findOrFail($eventId);

        $this->assertParticipable($event);

        return $event;
    }

    private function assertParticipable(Event $event): void
    {
        abort_unless($event->is_published && ! $event->is_cancelled && ! $event->is_private, 404, 'Encontro não disponível.');
    }

    private function managedEvent(Request $request, int $eventId): Event
    {
        $event = Event::query()
            ->where('app_id', $this->context->id())
            ->where('kind', 'community')
            ->findOrFail($eventId);

        $this->assertCanManage($request, $event);

        return $event;
    }

    private function assertCanManage(Request $request, Event $event): void
    {
        $user = $request->user();
        $admin = $user?->hasProfile('Administrador') ?? false;

        abort_unless(
            $user && ($admin || (int) $event->created_by_user_id === (int) $user->id),
            403,
            'Você não pode gerenciar este encontro.'
        );
    }

    private function summary(Event $event, bool $forceAttendees = false): array
    {
        $base = EventAttendance::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id);

        $counts = [
            'interested' => (clone $base)->where('status', EventAttendance::STATUS_INTERESTED)->count(),
            'going' => (clone $base)->where('status', EventAttendance::STATUS_GOING)->count(),
            'attended' => (clone $base)->where('status', EventAttendance::STATUS_ATTENDED)->count(),
        ];

        $attendees = [];
        if ($forceAttendees || $event->show_attendees) {
            $attendees = EventAttendance::query()
                ->where('event_attendances.app_id', $this->context->id())
                ->where('event_attendances.event_id', $event->id)
                ->whereIn('event_attendances.status', [EventAttendance::STATUS_GOING, EventAttendance::STATUS_ATTENDED])
                ->join('users as u', 'u.id', '=', 'event_attendances.user_id')
                ->select([
                    'event_attendances.user_id',
                    'event_attendances.status',
                    'u.first_name',
                    'u.last_name',
                    'u.user_name',
                    'u.avatar',
                ])
                ->orderByRaw("CASE event_attendances.status WHEN 'attended' THEN 1 ELSE 2 END")
                ->orderBy('event_attendances.created_at')
                ->limit(24)
                ->get()
                ->all();
        }

        return [
            'event_id' => (int) $event->id,
            'counts' => $counts,
            'capacity' => $event->max_attendees ? (int) $event->max_attendees : null,
            'show_attendees' => (bool) $event->show_attendees,
            'attendees' => $attendees,
        ];
    }

    private function attendancePayload(EventAttendance $attendance): array
    {
        return [
            'id' => (int) $attendance->id,
            'status' => $attendance->status,
            'checked_in_at' => $attendance->checked_in_at,
            'checkin_method' => $attendance->checkin_method,
            'distance_meters' => $attendance->distance_meters,
        ];
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
