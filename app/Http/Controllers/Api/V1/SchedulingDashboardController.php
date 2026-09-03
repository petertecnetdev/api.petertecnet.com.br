<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SchedulingResource;
use App\Services\Scheduling\SchedulingAuthorizationService;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchedulingDashboardController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SchedulingAuthorizationService $authorization
    ) {
    }

    public function overview(Request $request, int $establishment): JsonResponse
    {
        $model = $this->authorization->managedEstablishment(
            $request,
            $this->context->id(),
            $establishment
        );
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = now($timezone);
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('type', 'appointment')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $model->id)
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
            $hour => ['hour' => sprintf('%02d:00', $hour), 'scheduled' => 0, 'completed' => 0],
        ])->all();

        foreach ($orders as $order) {
            $status = strtolower((string) ($order->appointment_status ?: $order->status ?: 'pending'));
            $scheduledAt = Carbon::parse($order->order_datetime)->timezone($timezone);
            $scheduledEnd = $scheduledAt->copy()->addMinutes(max(5, (int) ($order->total_duration ?: 30)));
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
                $completedAt = $order->attended_at
                    ? Carbon::parse($order->attended_at)->timezone($timezone)
                    : $scheduledEnd;
                $timeline[(int) $completedAt->format('G')]['completed']++;
            } elseif (in_array($status, ['cancelled', 'canceled', 'rejected'], true)) {
                $summary['cancelled']++;
            } elseif (in_array($status, ['no_show', 'not_attended'], true)) {
                $summary['no_show']++;
            }
        }

        $next = $orders->first(fn (Order $order) =>
            in_array(strtolower((string) ($order->appointment_status ?: $order->status)), ['pending', 'confirmed'], true)
            && Carbon::parse($order->order_datetime)->timezone($timezone)->gte($now)
        );

        return response()->json([
            'success' => true,
            'data' => [
                'establishment' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'fantasy' => $model->fantasy,
                    'slug' => $model->slug,
                ],
                'date' => $now->toDateString(),
                'timezone' => $timezone,
                'updated_at' => $now->toIso8601String(),
                'summary' => $summary,
                'timeline' => collect($timeline)
                    ->filter(fn ($point, $hour) => $point['scheduled'] > 0 || $point['completed'] > 0 || ($hour >= 7 && $hour <= 22))
                    ->values(),
                'active_resources' => SchedulingResource::query()
                    ->where('app_id', $this->context->id())
                    ->where('establishment_id', $model->id)
                    ->where('is_active', true)
                    ->count(),
                'next_appointment' => $next ? $this->nextAppointment($next) : null,
            ],
        ]);
    }

    private function nextAppointment(Order $order): array
    {
        $resourceIds = DB::table('order_scheduling_resource')
            ->where('order_id', $order->id)
            ->pluck('scheduling_resource_id');

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_datetime' => Carbon::parse($order->order_datetime)
                ->timezone(config('app.timezone', 'America/Sao_Paulo'))
                ->toIso8601String(),
            'status' => strtolower((string) ($order->appointment_status ?: $order->status)),
            'client' => $order->client,
            'provider' => $order->attendant?->user,
            'resources' => SchedulingResource::query()
                ->whereIn('id', $resourceIds)
                ->get(['id', 'type', 'name', 'capacity']),
            'total_duration' => (int) ($order->total_duration ?: 30),
        ];
    }
}
