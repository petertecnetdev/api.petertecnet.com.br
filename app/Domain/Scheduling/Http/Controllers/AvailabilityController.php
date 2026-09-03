<?php

namespace App\Domain\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\EmployerSchedule;
use App\Models\Order;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends Controller
{
    private const TZ = 'America/Sao_Paulo';

    public function __construct(private readonly ApplicationContext $context) {}

    public function times(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer',
            'date' => 'required|date',
            'duration' => 'required|integer|min:5|max:1440',
        ]);
        $employerId = $this->scopedEmployerId((int) $data['employer_id']);
        $date = Carbon::parse($data['date'], self::TZ)->startOfDay();
        $times = $this->availableTimesForDate($employerId, $date, (int) $data['duration']);

        return response()->json([
            'date' => $date->toDateString(),
            'available' => count($times) > 0,
            'available_times' => $times,
        ]);
    }

    public function dates(Request $request)
    {
        $data = $request->validate([
            'employer_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'days' => 'nullable|integer|min:1|max:60',
            'duration' => 'required|integer|min:5|max:1440',
        ]);

        $startDate = isset($data['start_date'])
            ? Carbon::parse($data['start_date'], self::TZ)->startOfDay()
            : Carbon::now(self::TZ)->startOfDay();
        $days = (int) ($data['days'] ?? 14);
        $duration = (int) $data['duration'];
        $employerId = $this->scopedEmployerId((int) $data['employer_id']);
        $availableDates = [];
        $timesByDate = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $times = $this->availableTimesForDate($employerId, $date, $duration);
            if (count($times) > 0) {
                $key = $date->toDateString();
                $availableDates[] = $key;
                $timesByDate[$key] = $times;
            }
        }

        return response()->json([
            'available_dates' => $availableDates,
            'available_times_by_date' => $timesByDate,
            'days_checked' => $days,
        ]);
    }

    private function scopedEmployerId(int $employerId): int
    {
        $exists = Employer::query()
            ->whereKey($employerId)
            ->whereHas('establishment', function ($query) {
                $query
                    ->forApplication($this->context->id())
                    ->where('is_cancelled', false);
            })
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'employer_id' => ['Profissional não encontrado neste aplicativo.'],
            ]);
        }

        return $employerId;
    }

    private function availableTimesForDate(int $employerId, Carbon $date, int $duration): array
    {
        $now = Carbon::now(self::TZ);
        $dayOfWeek = strtolower($date->format('l'));
        $dateString = $date->toDateString();

        $workSchedules = EmployerSchedule::query()
            ->where('employer_id', $employerId)
            ->where('day_of_week', $dayOfWeek)
            ->where('type', 'work')
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();
        if ($workSchedules->isEmpty()) {
            return [];
        }

        $reservations = EmployerSchedule::query()
            ->where('employer_id', $employerId)
            ->whereDate('reserved_date', $dateString)
            ->whereIn('type', ['break', 'holiday'])
            ->get();
        if ($reservations->contains(fn ($reservation) => $reservation->type === 'holiday')) {
            return [];
        }

        $breaks = $reservations->where('type', 'break')->values();
        $orders = Order::query()
            ->where('app_id', $this->context->id())
            ->where('attendant_id', $employerId)
            ->where('type', 'appointment')
            ->whereDate('order_datetime', $dateString)
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->get();
        $available = [];

        foreach ($workSchedules as $schedule) {
            $pointer = Carbon::parse($dateString . ' ' . $schedule->start_time, self::TZ);
            $workEnd = Carbon::parse($dateString . ' ' . $schedule->end_time, self::TZ);

            while ($pointer->copy()->addMinutes($duration)->lte($workEnd)) {
                $slotStart = $pointer->copy();
                $slotEnd = $slotStart->copy()->addMinutes($duration);
                $isPast = $date->isSameDay($now) && $slotStart->lte($now);

                $hitsBreak = $breaks->contains(function ($break) use ($dateString, $slotStart, $slotEnd) {
                    $breakStart = Carbon::parse($dateString . ' ' . ($break->start_time ?: '00:00'), self::TZ);
                    $breakEnd = Carbon::parse($dateString . ' ' . ($break->end_time ?: '23:59'), self::TZ);
                    return $slotStart->lt($breakEnd) && $slotEnd->gt($breakStart);
                });

                $hitsOrder = $orders->contains(function ($order) use ($slotStart, $slotEnd) {
                    $orderStart = Carbon::parse($order->order_datetime, self::TZ);
                    $orderDuration = max(5, (int) ($order->total_duration ?: 30));
                    $orderEnd = $orderStart->copy()->addMinutes($orderDuration);
                    return $slotStart->lt($orderEnd) && $slotEnd->gt($orderStart);
                });

                if (! $isPast && ! $hitsBreak && ! $hitsOrder) {
                    $available[] = $slotStart->format('H:i');
                }
                $pointer->addMinutes(15);
            }
        }

        return array_values(array_unique($available));
    }
}
