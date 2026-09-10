<?php

namespace App\Domain\Events\Services;

use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\EventAgendaSetting;
use App\Models\EventPass;
use App\Models\EventSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EventAgendaMaintenanceService
{
    public function __construct(private readonly EventDuplicationService $duplicator) {}

    public function replenishAll(): array
    {
        $created = 0;
        $existing = 0;
        $retired = 0;
        $failed = [];
        $appSlugs = [];

        EventAgendaSetting::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($settings) use (&$created, &$existing, &$retired, &$failed, &$appSlugs) {
                foreach ($settings as $setting) {
                    try {
                        $appId = (int) $setting->app_id;
                        if (! array_key_exists($appId, $appSlugs)) {
                            $appSlugs[$appId] = Application::query()->whereKey($appId)->value('slug');
                        }

                        $result = $this->replenishProduction(
                            $appId,
                            (int) $setting->production_id,
                            (int) ($setting->generation_weeks ?: 1),
                            $appSlugs[$appId],
                        );

                        $created += $result['created_count'];
                        $existing += $result['existing_count'];
                        $retired += $result['retired_count'];
                    } catch (\Throwable $exception) {
                        $failed[] = [
                            'setting_id' => (int) $setting->id,
                            'production_id' => (int) $setting->production_id,
                            'message' => $exception->getMessage(),
                        ];
                        Log::warning('Falha ao repor agenda semanal.', [
                            'setting_id' => $setting->id,
                            'production_id' => $setting->production_id,
                            'exception' => $exception,
                        ]);
                    }
                }
            });

        return [
            'created_count' => $created,
            'existing_count' => $existing,
            'retired_count' => $retired,
            'failed_count' => count($failed),
            'failed' => $failed,
        ];
    }

    public function replenishProduction(int $appId, int $productionId, int $weeks, ?string $appSlug = null): array
    {
        $weeks = $this->normalizeWeeks($weeks);
        $appSlug ??= Application::query()->whereKey($appId)->value('slug');

        $schedules = EventSchedule::query()
            ->where('app_id', $appId)
            ->where('production_id', $productionId)
            ->where('is_active', true)
            ->whereNotNull('source_event_id')
            ->with('sourceEvent')
            ->orderBy('day_of_week')
            ->get();

        $created = 0;
        $existing = 0;
        $retired = 0;
        $events = [];

        foreach ($schedules as $schedule) {
            $result = $this->replenishSchedule($schedule, $weeks, $appSlug);
            $created += $result['created_count'];
            $existing += $result['existing_count'];
            $retired += $result['retired_count'];
            array_push($events, ...$result['events']);
        }

        return [
            'created_count' => $created,
            'existing_count' => $existing,
            'retired_count' => $retired,
            'events' => $events,
        ];
    }

    public function replenishSchedule(EventSchedule $schedule, int $weeks, ?string $appSlug = null): array
    {
        $weeks = $this->normalizeWeeks($weeks);
        $schedule->loadMissing('sourceEvent');
        $source = $schedule->sourceEvent;

        if (! $source || (int) $source->app_id !== (int) $schedule->app_id || (int) $source->production_id !== (int) $schedule->production_id) {
            return ['created_count' => 0, 'existing_count' => 0, 'retired_count' => 0, 'events' => []];
        }

        $appSlug ??= Application::query()->whereKey($schedule->app_id)->value('slug');
        $targetDates = $this->targetDates($schedule, $weeks);
        $retired = $this->retireExcessFutureOccurrences($schedule, $targetDates);

        $created = 0;
        $existing = 0;
        $events = [];

        foreach ($targetDates as $date) {
            [$event, $wasCreated] = $this->ensureOccurrence($schedule, $source, $date, $appSlug);
            $events[] = $event;
            $wasCreated ? $created++ : $existing++;
        }

        return [
            'created_count' => $created,
            'existing_count' => $existing,
            'retired_count' => $retired,
            'target_dates' => $targetDates,
            'events' => $events,
        ];
    }

    public function reconcileTemplateChange(EventSchedule $schedule, ?int $previousSourceEventId): int
    {
        if (! $previousSourceEventId || $previousSourceEventId === (int) $schedule->source_event_id) {
            return 0;
        }

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $future = Event::query()
            ->where('app_id', $schedule->app_id)
            ->where('event_schedule_id', $schedule->id)
            ->where('start_date', '>', Carbon::now($timezone))
            ->get();

        $retired = 0;
        foreach ($future as $event) {
            if ($this->hasCommercialActivity($event)) {
                continue;
            }

            $this->retireOccurrence($event, $previousSourceEventId);
            $retired++;
        }

        return $retired;
    }

    private function ensureOccurrence(EventSchedule $schedule, Event $source, string $date, ?string $appSlug): array
    {
        return DB::transaction(function () use ($schedule, $source, $date, $appSlug) {
            $lockedSchedule = EventSchedule::query()
                ->where('app_id', $schedule->app_id)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Event::query()
                ->where('app_id', $lockedSchedule->app_id)
                ->where('event_schedule_id', $lockedSchedule->id)
                ->whereDate('event_schedule_occurrence_date', $date)
                ->first();

            if ($existing) {
                return [$existing, false];
            }

            $timezone = config('app.timezone', 'America/Sao_Paulo');
            $sourceStart = $source->start_date ? Carbon::parse($source->start_date, $timezone) : null;

            if (
                $sourceStart
                && $sourceStart->format('Y-m-d') === $date
                && $sourceStart->gt(Carbon::now($timezone))
                && (! $source->event_schedule_id || (int) $source->event_schedule_id === (int) $lockedSchedule->id)
            ) {
                $source->forceFill([
                    'event_schedule_id' => $lockedSchedule->id,
                    'event_schedule_occurrence_date' => $date,
                ])->saveQuietly();

                return [$source->fresh(), false];
            }

            $duplicate = $this->duplicator->duplicate(
                $source,
                $date,
                (int) $lockedSchedule->app_id,
                $appSlug,
            );

            $duplicate->forceFill([
                'event_schedule_id' => $lockedSchedule->id,
                'event_schedule_occurrence_date' => $date,
                'is_published' => (bool) $source->is_published,
                'is_approved' => $source->is_approved,
            ])->saveQuietly();

            return [$duplicate->fresh(), true];
        });
    }

    private function targetDates(EventSchedule $schedule, int $weeks): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $daysAhead = ((int) $schedule->day_of_week - (int) $now->dayOfWeek + 7) % 7;
        $first = $now->copy()->startOfDay()->addDays($daysAhead);
        $clock = substr((string) $schedule->start_time, 0, 8);
        $firstStart = $first->copy()->setTimeFromTimeString($clock);

        if ($firstStart->lte($now)) {
            $first->addWeek();
        }

        $dates = [];
        for ($week = 0; $week < $weeks; $week++) {
            $dates[] = $first->copy()->addWeeks($week)->format('Y-m-d');
        }

        return $dates;
    }

    private function retireExcessFutureOccurrences(EventSchedule $schedule, array $targetDates): int
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $future = Event::query()
            ->where('app_id', $schedule->app_id)
            ->where('event_schedule_id', $schedule->id)
            ->where('start_date', '>', Carbon::now($timezone))
            ->whereNotIn('event_schedule_occurrence_date', $targetDates)
            ->get();

        $retired = 0;
        foreach ($future as $event) {
            if ($this->hasCommercialActivity($event)) {
                continue;
            }

            $this->retireOccurrence($event, (int) $schedule->source_event_id);
            $retired++;
        }

        return $retired;
    }

    private function retireOccurrence(Event $event, ?int $sourceEventId): void
    {
        $isTemplateSource = $sourceEventId && (int) $event->id === $sourceEventId;

        $event->forceFill([
            'event_schedule_id' => null,
            'event_schedule_occurrence_date' => null,
            'is_published' => $isTemplateSource ? (bool) $event->is_published : false,
            'is_cancelled' => $isTemplateSource ? (bool) $event->is_cancelled : true,
        ])->saveQuietly();
    }

    private function hasCommercialActivity(Event $event): bool
    {
        if (CommerceOrder::query()->where('event_id', $event->id)->exists()) {
            return true;
        }

        return EventPass::query()
            ->whereHas('ticket', fn ($tickets) => $tickets->where('event_id', $event->id))
            ->exists();
    }

    private function normalizeWeeks(int $weeks): int
    {
        return max(1, min(3, $weeks));
    }
}
