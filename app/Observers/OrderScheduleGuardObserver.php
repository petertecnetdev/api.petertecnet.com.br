<?php

namespace App\Observers;

use App\Models\EmployerSchedule;
use App\Models\Order;
use Carbon\Carbon;

class OrderScheduleGuardObserver
{
    public function creating(Order $order): void
    {
        if (
            $order->type !== 'appointment'
            || empty($order->attendant_id)
            || empty($order->order_datetime)
        ) {
            return;
        }

        $tz = 'America/Sao_Paulo';
        $start = Carbon::parse($order->order_datetime, $tz)
            ->setTimezone($tz)
            ->startOfMinute();
        $duration = max(1, (int) ($order->total_duration ?? 30));
        $end = $start->copy()->addMinutes($duration)->startOfMinute();
        $date = $start->toDateString();
        $dayOfWeek = strtolower($start->format('l'));

        $blockedSchedules = EmployerSchedule::query()
            ->where('employer_id', $order->attendant_id)
            ->whereIn('type', ['break', 'holiday'])
            ->where('is_active', true)
            ->where(function ($query) use ($date, $dayOfWeek) {
                $query->whereDate('reserved_date', $date)
                    ->orWhere(function ($recurring) use ($dayOfWeek) {
                        $recurring->whereNull('reserved_date')
                            ->where('day_of_week', $dayOfWeek);
                    });
            })
            ->orderBy('start_time')
            ->get(['type', 'start_time', 'end_time']);

        foreach ($blockedSchedules as $blockedSchedule) {
            $blockedStart = Carbon::parse(
                $date . ' ' . $blockedSchedule->start_time,
                $tz
            )->startOfMinute();
            $blockedEnd = Carbon::parse(
                $date . ' ' . $blockedSchedule->end_time,
                $tz
            )->startOfMinute();

            if ($blockedEnd->lte($blockedStart)) {
                continue;
            }

            if ($start->lt($blockedEnd) && $end->gt($blockedStart)) {
                $label = $blockedSchedule->type === 'holiday'
                    ? 'folga/indisponibilidade'
                    : 'pausa';

                abort(
                    422,
                    "Horário indisponível: o colaborador possui {$label} nesse período.\n" .
                        "Horário solicitado: {$start->format('H:i')} até {$end->format('H:i')}.\n" .
                        "Bloqueio cadastrado: {$blockedStart->format('H:i')} até {$blockedEnd->format('H:i')}."
                );
            }
        }
    }
}
