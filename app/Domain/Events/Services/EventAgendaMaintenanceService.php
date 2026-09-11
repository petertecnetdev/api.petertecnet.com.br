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
    private const MAX_GENERATION_WEEKS = 52;

    public function __construct(private readonly EventDuplicationService $duplicator) {}

    public function replenishAll(): array
    {
        $created = 0;
        $existing = 0;
        $retired = 0;
        $waiting = 0;
        $failed = [];
        $appSlugs = [];

        EventAgendaSetting::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($settings) use (&$created, &$existing, &$retired, &$waiting, &$failed, &$appSlugs) {
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
                        $waiting += $result['waiting_count'];
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
            'waiting_count' => $waiting,
            'failed_count' => count($failed),
            'failed' => $failed,
        ];
    }

    public function replenishProduction(
        int $appId,
        int $productionId,
        int $defaultWeeks,
        ?string $appSlug = null,
        bool $force = false,
    ): array {
        $defaultWeeks = $this->normalizeWeeks($defaultWeeks);
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
        $waiting = 0;
        $events = [];

        foreach ($schedules as $schedule) {
            $weeks = $schedule->generation_weeks ?: $defaultWeeks;
            $result = $this->replenishSchedule($schedule, (int) $weeks, $appSlug, $force);
            $created += $result['created_count'];
            $existing += $result['existing_count'];
            $retired += $result['retired_count'];
            $waiting += $result['waiting'] ? 1 : 0;
            array_push($events, ...$result['events']);
        }

        return [
            'created_count' => $created,
            'existing_count' => $existing,
            'retired_count' => $retired,
            'waiting_count' => $waiting,
            'events' => $events,
        ];
    }

    public function replenishSchedule(
        EventSchedule $schedule,
        ?int $weeks = null,
        ?string $appSlug = null,
        bool $force = false,
    ): array {
        $weeks = $this->normalizeWeeks($weeks ?: (int) ($schedule->generation_weeks ?: 1));
        $mode = $this->normalizeMode($schedule->generation_mode);
        $delayDays = $this->normalizeDelayDays((int) ($schedule->generation_delay_days ?: 1));

        $schedule->loadMissing('sourceEvent');
        $source = $schedule->sourceEvent;

        if (! $source || (int) $source->app_id !== (int) $schedule->app_id || (int) $source->production_id !== (int) $schedule->production_id) {
            return $this->emptyResult($mode, $delayDays, $weeks);
        }

        $appSlug ??= Application::query()->whereKey($schedule->app_id)->value('slug');
        $targetDates = $this->targetDates($schedule, $weeks);
        $retired = $this->retireExcessFutureOccurrences($schedule, $targetDates);

        if ($mode === 'delayed' && ! $force) {
            $linkedOccurrences = Event::query()
                ->where('app_id', $schedule->app_id)
                ->where('event_schedule_id', $schedule->id)
                ->orderBy('event_schedule_occurrence_date')
                ->get();

            if ($linkedOccurrences->isEmpty()) {
                [$event, $created] = $this->ensureOccurrence(
                    $schedule,
                    $source,
                    $targetDates[0],
                    $appSlug,
                );

                return [
                    'created_count' => $created ? 1 : 0,
                    'existing_count' => $created ? 0 : 1,
                    'retired_count' => $retired,
                    'target_dates' => [$targetDates[0]],
                    'events' => [$event],
                    'waiting' => true,
                    'generation_mode' => $mode,
                    'generation_delay_days' => $delayDays,
                    'generation_weeks' => $weeks,
                    'next_generation_at' => $this->generationAtForOccurrence($targetDates[0], $delayDays)->toIso8601String(),
                ];
            }

            $gate = $this->delayedGenerationGate($schedule, $linkedOccurrences, $delayDays);
            if (! $gate['eligible']) {
                return [
                    'created_count' => 0,
                    'existing_count' => 0,
                    'retired_count' => $retired,
                    'target_dates' => $targetDates,
                    'events' => [],
                    'waiting' => true,
                    'generation_mode' => $mode,
                    'generation_delay_days' => $delayDays,
                    'generation_weeks' => $weeks,
                    'next_generation_at' => $gate['next_generation_at'],
                ];
            }
        }

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
            'waiting' => false,
            'generation_mode' => $mode,
            'generation_delay_days' => $delayDays,
            'generation_weeks' => $weeks,
            'next_generation_at' => null,
        ];
    }

    public function retireRemovedSchedule(EventSchedule $schedule): int
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $future = Event::query()
            ->where('app_id', $schedule->app_id)
            ->where('event_schedule_id', $schedule->id)
            ->where('start_date', '>', Carbon::now($timezone))
            ->get();

        $retired = 0;
        foreach ($future as $event) {
            if ($this->hasCommercialActivity($event)) {
                $event->forceFill([
                    'event_schedule_id' => null,
                    'event_schedule_occurrence_date' => null,
                ])->saveQuietly();
                continue;
            }

            $this->retireOccurrence($event, (int) $schedule->source_event_id);
            $retired++;
        }

        return $retired;
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

    private function delayedGenerationGate(EventSchedule $schedule, $linkedOccurrences, int $delayDays): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $today = Carbon::now($timezone)->startOfDay();

        $completed = $linkedOccurrences
            ->filter(function (Event $event) use ($today, $timezone) {
                if (! $event->event_schedule_occurrence_date) {
                    return false;
                }

                return Carbon::parse($event->event_schedule_occurrence_date, $timezone)
                    ->startOfDay()
                    ->lt($today);
            })
            ->sortByDesc('event_schedule_occurrence_date')
            ->first();

        if ($completed) {
            $date = Carbon::parse($completed->event_schedule_occurrence_date, $timezone)->format('Y-m-d');
            $eligibleAt = $this->generationAtForOccurrence($date, $delayDays);

            return [
                'eligible' => Carbon::now($timezone)->gte($eligibleAt),
                'next_generation_at' => $eligibleAt->toIso8601String(),
            ];
        }

        $nextLinked = $linkedOccurrences
            ->filter(fn (Event $event) => (bool) $event->event_schedule_occurrence_date)
            ->sortBy('event_schedule_occurrence_date')
            ->first();

        if ($nextLinked) {
            $date = Carbon::parse($nextLinked->event_schedule_occurrence_date, $timezone)->format('Y-m-d');

            return [
                'eligible' => false,
                'next_generation_at' => $this->generationAtForOccurrence($date, $delayDays)->toIso8601String(),
            ];
        }

        $fallbackDate = $this->targetDates($schedule, 1)[0];

        return [
            'eligible' => false,
            'next_generation_at' => $this->generationAtForOccurrence($fallbackDate, $delayDays)->toIso8601String(),
        ];
    }

    private function generationAtForOccurrence(string $occurrenceDate, int $delayDays): Carbon
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        return Carbon::createFromFormat('Y-m-d', $occurrenceDate, $timezone)
            ->startOfDay()
            ->addDays($delayDays);
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

    private function normalizeMode(?string $mode): string
    {
        return $mode === 'delayed' ? 'delayed' : 'immediate';
    }

    private function normalizeDelayDays(int $days): int
    {
        return max(1, min(6, $days));
    }

    private function normalizeWeeks(int $weeks): int
    {
        return max(1, min(self::MAX_GENERATION_WEEKS, $weeks));
    }

    private function emptyResult(string $mode, int $delayDays, int $weeks): array
    {
        return [
            'created_count' => 0,
            'existing_count' => 0,
            'retired_count' => 0,
            'target_dates' => [],
            'events' => [],
            'waiting' => false,
            'generation_mode' => $mode,
            'generation_delay_days' => $delayDays,
            'generation_weeks' => $weeks,
            'next_generation_at' => null,
        ];
    }
}
