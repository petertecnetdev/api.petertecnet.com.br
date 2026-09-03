<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Scheduling\SchedulingAuthorizationService;
use App\Services\Scheduling\SchedulingAvailabilityService;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchedulingAvailabilityController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SchedulingAvailabilityService $availability,
        private readonly SchedulingAuthorizationService $authorization
    ) {
    }

    public function times(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules([
            'date' => 'required|date',
        ]));

        $establishmentId = (int) $data['establishment_id'];
        $this->authorization->establishment($this->context->id(), $establishmentId);

        $date = Carbon::parse($data['date'], config('app.timezone', 'America/Sao_Paulo'))->startOfDay();
        $times = $this->availability->availableTimes(
            $this->context->id(),
            $establishmentId,
            $date,
            (int) $data['duration'],
            isset($data['provider_id']) ? (int) $data['provider_id'] : null,
            $data['resource_ids'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date->toDateString(),
                'available' => count($times) > 0,
                'available_times' => $times,
            ],
        ]);
    }

    public function dates(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules([
            'start_date' => 'nullable|date',
            'days' => 'nullable|integer|min:1|max:60',
        ]));

        $establishmentId = (int) $data['establishment_id'];
        $this->authorization->establishment($this->context->id(), $establishmentId);

        $start = isset($data['start_date'])
            ? Carbon::parse($data['start_date'], config('app.timezone', 'America/Sao_Paulo'))->startOfDay()
            : now(config('app.timezone', 'America/Sao_Paulo'))->startOfDay();
        $days = (int) ($data['days'] ?? 14);
        $duration = (int) $data['duration'];
        $availableDates = [];
        $timesByDate = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $times = $this->availability->availableTimes(
                $this->context->id(),
                $establishmentId,
                $date,
                $duration,
                isset($data['provider_id']) ? (int) $data['provider_id'] : null,
                $data['resource_ids'] ?? []
            );

            if ($times !== []) {
                $key = $date->toDateString();
                $availableDates[] = $key;
                $timesByDate[$key] = $times;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'available_dates' => $availableDates,
                'available_times_by_date' => $timesByDate,
                'days_checked' => $days,
            ],
        ]);
    }

    private function rules(array $extra): array
    {
        return array_merge([
            'establishment_id' => 'required|integer|exists:establishments,id',
            'provider_id' => 'nullable|integer|exists:employers,id|required_without:resource_ids',
            'resource_ids' => 'nullable|array|max:20|required_without:provider_id',
            'resource_ids.*' => 'integer|distinct|exists:scheduling_resources,id',
            'duration' => 'required|integer|min:5|max:1440',
        ], $extra);
    }
}
