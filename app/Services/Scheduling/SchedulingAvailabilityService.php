<?php

namespace App\Services\Scheduling;

use App\Models\Employer;
use App\Models\EmployerSchedule;
use App\Models\Order;
use App\Models\SchedulingResource;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchedulingAvailabilityService
{
    private const SLOT_STEP_MINUTES = 15;

    public function availableTimes(
        int $appId,
        int $establishmentId,
        Carbon $date,
        int $duration,
        ?int $providerId = null,
        array $resourceIds = []
    ): array {
        $duration = max(5, $duration);
        $subjects = $this->subjects($appId, $establishmentId, $providerId, $resourceIds);

        if ($subjects->isEmpty()) {
            throw ValidationException::withMessages([
                'resources' => ['Informe ao menos um profissional ou recurso agendável.'],
            ]);
        }

        $baseWindows = $this->workWindowsForSubject($subjects->first(), $date);
        if ($baseWindows->isEmpty()) {
            return [];
        }

        $now = now(config('app.timezone', 'America/Sao_Paulo'));
        $available = [];

        foreach ($baseWindows as [$windowStart, $windowEnd]) {
            $pointer = $windowStart->copy();

            while ($pointer->copy()->addMinutes($duration)->lte($windowEnd)) {
                $slotStart = $pointer->copy();
                $slotEnd = $slotStart->copy()->addMinutes($duration);

                if ($slotStart->gt($now) && $subjects->every(
                    fn (array $subject) => $this->subjectIsAvailable($subject, $date, $slotStart, $slotEnd, $appId)
                )) {
                    $available[] = $slotStart->format('H:i');
                }

                $pointer->addMinutes(self::SLOT_STEP_MINUTES);
            }
        }

        return array_values(array_unique($available));
    }

    public function assertSlotAvailable(
        int $appId,
        int $establishmentId,
        Carbon $start,
        int $duration,
        ?int $providerId = null,
        array $resourceIds = [],
        ?int $excludeOrderId = null
    ): void {
        $subjects = $this->subjects($appId, $establishmentId, $providerId, $resourceIds);

        if ($subjects->isEmpty()) {
            throw ValidationException::withMessages([
                'resources' => ['Informe ao menos um profissional ou recurso agendável.'],
            ]);
        }

        $end = $start->copy()->addMinutes(max(5, $duration));
        $date = $start->copy()->startOfDay();

        foreach ($subjects as $subject) {
            if (! $this->subjectIsAvailable($subject, $date, $start, $end, $appId, $excludeOrderId)) {
                throw ValidationException::withMessages([
                    'scheduled_at' => ['Um dos profissionais ou recursos selecionados não está disponível nesse horário.'],
                ]);
            }
        }
    }

    public function resources(int $appId, int $establishmentId, array $resourceIds): Collection
    {
        $ids = collect($resourceIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $resources = SchedulingResource::query()
            ->where('app_id', $appId)
            ->where('establishment_id', $establishmentId)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get();

        if ($resources->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'resource_ids' => ['Um ou mais recursos não pertencem ao mesmo estabelecimento e aplicativo.'],
            ]);
        }

        return $resources;
    }

    private function subjects(int $appId, int $establishmentId, ?int $providerId, array $resourceIds): Collection
    {
        $subjects = collect();

        if ($providerId) {
            $provider = Employer::query()
                ->whereKey($providerId)
                ->where('establishment_id', $establishmentId)
                ->whereHas('establishment', fn ($query) => $query->where('app_id', $appId))
                ->first();

            if (! $provider) {
                throw ValidationException::withMessages([
                    'provider_id' => ['O profissional não pertence ao estabelecimento e aplicativo informados.'],
                ]);
            }

            $subjects->push(['kind' => 'provider', 'model' => $provider]);
        }

        foreach ($this->resources($appId, $establishmentId, $resourceIds) as $resource) {
            if ($providerId && (int) $resource->employer_id === $providerId) {
                continue;
            }

            $subjects->push(['kind' => 'resource', 'model' => $resource]);
        }

        return $subjects;
    }

    private function workWindowsForSubject(array $subject, Carbon $date): Collection
    {
        $dateString = $date->toDateString();
        $dayOfWeek = strtolower($date->format('l'));
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        if ($subject['kind'] === 'provider') {
            $rows = EmployerSchedule::query()
                ->where('employer_id', $subject['model']->id)
                ->where('day_of_week', $dayOfWeek)
                ->where('type', 'work')
                ->where('is_active', true)
                ->orderBy('start_time')
                ->get();
        } else {
            /** @var SchedulingResource $resource */
            $resource = $subject['model'];
            $rows = $resource->schedules()
                ->where('day_of_week', $dayOfWeek)
                ->where('type', 'work')
                ->where('is_active', true)
                ->orderBy('start_time')
                ->get();

            if ($rows->isEmpty() && $resource->employer_id) {
                $rows = EmployerSchedule::query()
                    ->where('employer_id', $resource->employer_id)
                    ->where('day_of_week', $dayOfWeek)
                    ->where('type', 'work')
                    ->where('is_active', true)
                    ->orderBy('start_time')
                    ->get();
            }
        }

        return $rows
            ->filter(fn ($row) => $row->start_time && $row->end_time)
            ->map(fn ($row) => [
                Carbon::parse($dateString . ' ' . $row->start_time, $timezone),
                Carbon::parse($dateString . ' ' . $row->end_time, $timezone),
            ])
            ->values();
    }

    private function subjectIsAvailable(
        array $subject,
        Carbon $date,
        Carbon $slotStart,
        Carbon $slotEnd,
        int $appId,
        ?int $excludeOrderId = null
    ): bool {
        $windows = $this->workWindowsForSubject($subject, $date);
        $insideWorkWindow = $windows->contains(
            fn (array $window) => $slotStart->gte($window[0]) && $slotEnd->lte($window[1])
        );

        if (! $insideWorkWindow || $this->hasBlockingSchedule($subject, $date, $slotStart, $slotEnd)) {
            return false;
        }

        return ! $this->hasOrderConflict($subject, $slotStart, $slotEnd, $appId, $excludeOrderId);
    }

    private function hasBlockingSchedule(array $subject, Carbon $date, Carbon $slotStart, Carbon $slotEnd): bool
    {
        $dateString = $date->toDateString();
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        if ($subject['kind'] === 'provider') {
            $rows = EmployerSchedule::query()
                ->where('employer_id', $subject['model']->id)
                ->whereDate('reserved_date', $dateString)
                ->whereIn('type', ['break', 'holiday', 'blocked'])
                ->where('is_active', true)
                ->get();
        } else {
            /** @var SchedulingResource $resource */
            $resource = $subject['model'];
            $rows = $resource->schedules()
                ->whereDate('reserved_date', $dateString)
                ->whereIn('type', ['break', 'holiday', 'blocked'])
                ->where('is_active', true)
                ->get();

            if ($rows->isEmpty() && $resource->employer_id) {
                $rows = EmployerSchedule::query()
                    ->where('employer_id', $resource->employer_id)
                    ->whereDate('reserved_date', $dateString)
                    ->whereIn('type', ['break', 'holiday', 'blocked'])
                    ->where('is_active', true)
                    ->get();
            }
        }

        return $rows->contains(function ($row) use ($dateString, $slotStart, $slotEnd, $timezone) {
            if ($row->type === 'holiday') {
                return true;
            }

            $start = Carbon::parse($dateString . ' ' . ($row->start_time ?: '00:00'), $timezone);
            $end = Carbon::parse($dateString . ' ' . ($row->end_time ?: '23:59'), $timezone);

            return $slotStart->lt($end) && $slotEnd->gt($start);
        });
    }

    private function hasOrderConflict(
        array $subject,
        Carbon $slotStart,
        Carbon $slotEnd,
        int $appId,
        ?int $excludeOrderId
    ): bool {
        $query = Order::query()
            ->where('app_id', $appId)
            ->where('type', 'appointment')
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId));

        if ($subject['kind'] === 'provider') {
            $query->where('attendant_id', $subject['model']->id);
        } else {
            $query->whereIn('id', DB::table('order_scheduling_resource')
                ->select('order_id')
                ->where('scheduling_resource_id', $subject['model']->id));
        }

        return $query
            ->where('order_datetime', '<', $slotEnd)
            ->whereRaw('DATE_ADD(order_datetime, INTERVAL GREATEST(total_duration, 5) MINUTE) > ?', [$slotStart])
            ->exists();
    }
}
