<?php

namespace App\Services\Reporting;

use App\Models\AdministrativeReportSchedule;
use Carbon\Carbon;

class ReportScheduleService
{
    public function nextRun(AdministrativeReportSchedule $schedule, ?Carbon $after = null): Carbon
    {
        $zone = $schedule->timezone ?: 'America/Sao_Paulo';
        $cursor = ($after ?: now())->copy()->setTimezone($zone)->startOfMinute();
        $candidate = $cursor->copy()->setTime((int) $schedule->hour, (int) $schedule->minute);

        if ($schedule->cadence === 'daily') {
            if ($candidate->lte($cursor)) $candidate->addDay();
        } elseif ($schedule->cadence === 'weekly') {
            $target = (int) ($schedule->day_of_week ?? 1);
            while ($candidate->dayOfWeek !== $target || $candidate->lte($cursor)) {
                $candidate->addDay()->setTime((int) $schedule->hour, (int) $schedule->minute);
            }
        } else {
            $target = min(max((int) ($schedule->day_of_month ?? 1), 1), 28);
            $candidate = $cursor->copy()->setDate($cursor->year, $cursor->month, $target)->setTime((int) $schedule->hour, (int) $schedule->minute);
            if ($candidate->lte($cursor)) {
                $candidate->addMonthNoOverflow();
                $candidate->setDate($candidate->year, $candidate->month, $target)->setTime((int) $schedule->hour, (int) $schedule->minute);
            }
        }

        return $candidate->utc();
    }
}
