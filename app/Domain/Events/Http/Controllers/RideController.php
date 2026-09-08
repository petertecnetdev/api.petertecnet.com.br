<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RideController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request, int $eventId)
    {
        $event = $this->event($eventId);
        $userId = (int) $request->user('api')->id;

        $rides = DB::table('rides as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.app_id', $this->context->id())
            ->where('r.event_id', $event->id)
            ->where('r.status', '!=', 'cancelled')
            ->select([
                'r.id', 'r.user_id', 'r.kind', 'r.origin_city', 'r.origin_region', 'r.origin_label',
                'r.meeting_point', 'r.departure_at', 'r.seats', 'r.cost_type', 'r.suggested_cost',
                'r.notes', 'r.status', 'r.created_at', 'u.first_name', 'u.last_name', 'u.avatar',
            ])
            ->orderByRaw("CASE WHEN r.status = 'open' THEN 0 WHEN r.status = 'full' THEN 1 ELSE 2 END")
            ->orderBy('r.departure_at')
            ->get();

        $rideIds = $rides->pluck('id')->map(fn ($id) => (int) $id)->all();
        $acceptedByRide = empty($rideIds) ? collect() : DB::table('ride_requests')
            ->whereIn('ride_id', $rideIds)
            ->where('status', 'accepted')
            ->selectRaw('ride_id, COALESCE(SUM(seats), 0) as accepted_seats')
            ->groupBy('ride_id')
            ->pluck('accepted_seats', 'ride_id');

        $myRequests = empty($rideIds) ? collect() : DB::table('ride_requests')
            ->whereIn('ride_id', $rideIds)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('ride_id');

        $ownedRideIds = $rides->where('user_id', $userId)->pluck('id')->all();
        $ownerRequests = empty($ownedRideIds) ? collect() : DB::table('ride_requests as rr')
            ->join('users as u', 'u.id', '=', 'rr.user_id')
            ->whereIn('rr.ride_id', $ownedRideIds)
            ->select([
                'rr.id', 'rr.ride_id', 'rr.user_id', 'rr.seats', 'rr.message', 'rr.status',
                'rr.created_at', 'u.first_name', 'u.last_name', 'u.avatar',
            ])
            ->orderByRaw("CASE WHEN rr.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('rr.created_at')
            ->get()
            ->groupBy('ride_id');

        $payload = $rides->map(function ($ride) use ($acceptedByRide, $myRequests, $ownerRequests, $userId) {
            $accepted = (int) ($acceptedByRide[$ride->id] ?? 0);
            $isOwner = (int) $ride->user_id === $userId;
            $mine = $myRequests->get($ride->id);
            $canSeeMeetingPoint = $isOwner || ($mine && $mine->status === 'accepted');

            return [
                'id' => (int) $ride->id,
                'kind' => $ride->kind,
                'status' => $ride->status,
                'origin_city' => $ride->origin_city,
                'origin_region' => $ride->origin_region,
                'origin_label' => $ride->origin_label,
                'meeting_point' => $canSeeMeetingPoint ? $ride->meeting_point : null,
                'departure_at' => $ride->departure_at,
                'seats' => (int) $ride->seats,
                'available_seats' => max(0, (int) $ride->seats - $accepted),
                'cost_type' => $ride->cost_type,
                'suggested_cost' => $ride->suggested_cost !== null ? (float) $ride->suggested_cost : null,
                'notes' => $ride->notes,
                'is_owner' => $isOwner,
                'owner' => [
                    'id' => (int) $ride->user_id,
                    'first_name' => $ride->first_name,
                    'last_name' => $ride->last_name,
                    'avatar' => $ride->avatar,
                ],
                'my_request' => $mine ? [
                    'id' => (int) $mine->id,
                    'seats' => (int) $mine->seats,
                    'message' => $mine->message,
                    'status' => $mine->status,
                ] : null,
                'requests' => $isOwner ? ($ownerRequests->get($ride->id, collect())->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'user_id' => (int) $item->user_id,
                    'seats' => (int) $item->seats,
                    'message' => $item->message,
                    'status' => $item->status,
                    'created_at' => $item->created_at,
                    'user' => [
                        'id' => (int) $item->user_id,
                        'first_name' => $item->first_name,
                        'last_name' => $item->last_name,
                        'avatar' => $item->avatar,
                    ],
                ])->values()->all()) : [],
            ];
        })->values();

        return response()->json([
            'rides' => $payload,
            'event' => ['id' => (int) $event->id, 'title' => $event->title],
        ]);
    }

    public function store(Request $request, int $eventId)
    {
        $event = $this->event($eventId);
        $data = $request->validate([
            'kind' => 'required|in:offer,request',
            'origin_city' => 'required|string|max:120',
            'origin_region' => 'nullable|string|max:160',
            'origin_label' => 'nullable|string|max:200',
            'meeting_point' => 'nullable|string|max:255',
            'departure_at' => 'required|date',
            'seats' => 'nullable|integer|min:1|max:8',
            'cost_type' => 'nullable|in:free,share,negotiated',
            'suggested_cost' => 'nullable|numeric|min:0|max:99999.99',
            'notes' => 'nullable|string|max:1200',
        ]);

        $departureAt = Carbon::parse($data['departure_at']);
        if ($departureAt->isPast()) {
            throw ValidationException::withMessages(['departure_at' => 'O horário de saída precisa estar no futuro.']);
        }

        $userId = (int) $request->user('api')->id;
        $existing = DB::table('rides')
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('kind', $data['kind'])
            ->whereIn('status', ['open', 'full', 'confirmed'])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['kind' => 'Você já possui um Ride ativo deste tipo para este evento.']);
        }

        $id = DB::table('rides')->insertGetId([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'user_id' => $userId,
            'kind' => $data['kind'],
            'origin_city' => trim($data['origin_city']),
            'origin_region' => isset($data['origin_region']) ? trim($data['origin_region']) : null,
            'origin_label' => isset($data['origin_label']) ? trim($data['origin_label']) : null,
            'meeting_point' => isset($data['meeting_point']) ? trim($data['meeting_point']) : null,
            'departure_at' => $departureAt,
            'seats' => $data['kind'] === 'request' ? 1 : (int) ($data['seats'] ?? 1),
            'cost_type' => $data['kind'] === 'request' ? 'negotiated' : ($data['cost_type'] ?? 'free'),
            'suggested_cost' => $data['kind'] === 'request' ? null : ($data['suggested_cost'] ?? null),
            'notes' => $data['notes'] ?? null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Ride publicado.', 'ride_id' => $id], 201);
    }

    public function requestSeat(Request $request, int $rideId)
    {
        $data = $request->validate([
            'seats' => 'nullable|integer|min:1|max:4',
            'message' => 'nullable|string|max:500',
        ]);
        $userId = (int) $request->user('api')->id;

        return DB::transaction(function () use ($rideId, $data, $userId) {
            $ride = DB::table('rides')->where('id', $rideId)->lockForUpdate()->first();
            $this->assertRideContext($ride);

            if ($ride->kind !== 'offer' || !in_array($ride->status, ['open', 'full'], true)) {
                throw ValidationException::withMessages(['ride' => 'Este Ride não está aceitando novas solicitações.']);
            }
            if ((int) $ride->user_id === $userId) {
                throw ValidationException::withMessages(['ride' => 'Você não pode solicitar vaga na própria carona.']);
            }

            $seats = (int) ($data['seats'] ?? 1);
            $accepted = (int) DB::table('ride_requests')->where('ride_id', $rideId)->where('status', 'accepted')->sum('seats');
            if ($accepted + $seats > (int) $ride->seats) {
                throw ValidationException::withMessages(['seats' => 'Não há vagas suficientes disponíveis.']);
            }

            $existing = DB::table('ride_requests')->where('ride_id', $rideId)->where('user_id', $userId)->first();
            if ($existing && in_array($existing->status, ['pending', 'accepted'], true)) {
                throw ValidationException::withMessages(['ride' => 'Você já possui uma solicitação ativa para esta carona.']);
            }

            if ($existing) {
                DB::table('ride_requests')->where('id', $existing->id)->update([
                    'seats' => $seats,
                    'message' => $data['message'] ?? null,
                    'status' => 'pending',
                    'responded_at' => null,
                    'updated_at' => now(),
                ]);
                $requestId = $existing->id;
            } else {
                $requestId = DB::table('ride_requests')->insertGetId([
                    'ride_id' => $rideId,
                    'user_id' => $userId,
                    'seats' => $seats,
                    'message' => $data['message'] ?? null,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json(['message' => 'Solicitação enviada ao motorista.', 'request_id' => $requestId], 201);
        });
    }

    public function respond(Request $request, int $rideId, int $requestId)
    {
        $data = $request->validate(['status' => 'required|in:accepted,rejected']);
        $userId = (int) $request->user('api')->id;

        return DB::transaction(function () use ($rideId, $requestId, $data, $userId) {
            $ride = DB::table('rides')->where('id', $rideId)->lockForUpdate()->first();
            $this->assertRideContext($ride);
            if ((int) $ride->user_id !== $userId) abort(403, 'Somente quem publicou a carona pode responder solicitações.');

            $rideRequest = DB::table('ride_requests')->where('id', $requestId)->where('ride_id', $rideId)->lockForUpdate()->first();
            if (!$rideRequest) abort(404, 'Solicitação não encontrada.');
            if ($rideRequest->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Esta solicitação já foi respondida.']);
            }

            if ($data['status'] === 'accepted') {
                $accepted = (int) DB::table('ride_requests')->where('ride_id', $rideId)->where('status', 'accepted')->sum('seats');
                if ($accepted + (int) $rideRequest->seats > (int) $ride->seats) {
                    throw ValidationException::withMessages(['seats' => 'Não há vagas suficientes para aceitar esta solicitação.']);
                }
            }

            DB::table('ride_requests')->where('id', $requestId)->update([
                'status' => $data['status'],
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

            $acceptedAfter = (int) DB::table('ride_requests')->where('ride_id', $rideId)->where('status', 'accepted')->sum('seats');
            DB::table('rides')->where('id', $rideId)->update([
                'status' => $acceptedAfter >= (int) $ride->seats ? 'full' : 'open',
                'updated_at' => now(),
            ]);

            return response()->json(['message' => $data['status'] === 'accepted' ? 'Solicitação aceita.' : 'Solicitação recusada.']);
        });
    }

    public function cancel(Request $request, int $rideId)
    {
        $ride = DB::table('rides')->where('id', $rideId)->first();
        $this->assertRideContext($ride);
        if ((int) $ride->user_id !== (int) $request->user('api')->id) abort(403, 'Você não pode cancelar este Ride.');

        DB::transaction(function () use ($rideId) {
            DB::table('rides')->where('id', $rideId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('ride_requests')->where('ride_id', $rideId)->where('status', 'pending')->update([
                'status' => 'cancelled', 'responded_at' => now(), 'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Ride cancelado.']);
    }

    private function event(int $eventId): Event
    {
        return Event::query()
            ->where('id', $eventId)
            ->where('app_id', $this->context->id())
            ->firstOrFail();
    }

    private function assertRideContext(?object $ride): void
    {
        if (!$ride || (int) $ride->app_id !== (int) $this->context->id()) abort(404, 'Ride não encontrado.');
    }
}
