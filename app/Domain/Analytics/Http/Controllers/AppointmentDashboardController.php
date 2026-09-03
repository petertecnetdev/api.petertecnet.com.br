<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AppointmentDashboardController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly ApplicationContext $context) {}

    public function overview(Request $request, string $slug)
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
            'Somente a gestão do estabelecimento pode visualizar estes indicadores.'
        );

        $now = now(self::TZ);
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->whereBetween('order_datetime', [$start, $end])
            ->with(['client:id,first_name,last_name,user_name', 'attendant.user:id,first_name,last_name,user_name'])
            ->orderBy('order_datetime')
            ->get();

        $summary = [
            'total' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'no_show' => 0,
        ];

        $timeline = collect(range(0, 23))->mapWithKeys(fn ($hour) => [
            $hour => [
                'hour' => sprintf('%02d:00', $hour),
                'scheduled' => 0,
                'completed' => 0,
            ],
        ])->all();

        foreach ($orders as $order) {
            $status = strtolower((string) ($order->appointment_status ?: $order->status ?: 'pending'));
            $scheduledAt = Carbon::parse($order->order_datetime)->timezone(self::TZ);
            $scheduledEnd = $scheduledAt->copy()->addMinutes(max(1, (int) ($order->total_duration ?: 30)));
            $hour = (int) $scheduledAt->format('G');

            $summary['total']++;
            $timeline[$hour]['scheduled']++;

            if ($status === 'pending') {
                $summary['pending']++;
            } elseif ($status === 'confirmed') {
                $summary['confirmed']++;
                if ($now->gte($scheduledAt) && $now->lt($scheduledEnd)) {
                    $summary['in_progress']++;
                }
            } elseif (in_array($status, ['completed', 'attended'], true)) {
                $summary['completed']++;
                $completionAt = $order->attended_at
                    ? Carbon::parse($order->attended_at)->timezone(self::TZ)
                    : $scheduledEnd;
                $timeline[(int) $completionAt->format('G')]['completed']++;
            } elseif (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
                $summary['cancelled']++;
            } elseif (in_array($status, ['no_show', 'not_attended'], true)) {
                $summary['no_show']++;
            }
        }

        $visibleHours = collect($timeline)
            ->filter(fn ($point, $hour) => $point['scheduled'] > 0 || $point['completed'] > 0 || ($hour >= 7 && $hour <= 22))
            ->values();

        $nextOrder = $orders->first(fn ($order) =>
            in_array(strtolower((string) ($order->appointment_status ?: $order->status)), ['pending', 'confirmed'], true)
            && Carbon::parse($order->order_datetime)->timezone(self::TZ)->gte($now)
        );

        return response()->json([
            'success' => true,
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
            ],
            'date' => $now->toDateString(),
            'timezone' => self::TZ,
            'updated_at' => $now->toIso8601String(),
            'summary' => $summary,
            'timeline' => $visibleHours,
            'next_appointment' => $nextOrder ? [
                'id' => $nextOrder->id,
                'order_number' => $nextOrder->order_number,
                'order_datetime' => Carbon::parse($nextOrder->order_datetime)->timezone(self::TZ)->toIso8601String(),
                'status' => strtolower((string) ($nextOrder->appointment_status ?: $nextOrder->status)),
                'client' => $nextOrder->client,
                'attendant' => $nextOrder->attendant?->user,
                'total_duration' => (int) ($nextOrder->total_duration ?: 30),
            ] : null,
        ]);
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
}
