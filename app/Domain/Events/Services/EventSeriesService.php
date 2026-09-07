<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EventSeriesService
{
    private const MAX_OCCURRENCES = 120;
    private const MAX_RANGE_DAYS = 366;

    public function __construct(private readonly EventDuplicationService $duplicator) {}

    public function create(Event $source, int $appId, ?string $appSlug, array $input, ?callable $onProgress = null): array
    {
        $data = Validator::make($input, [
            'mode' => ['required', 'in:dates,weekly'],
            'dates' => ['required_if:mode,dates', 'array', 'min:1', 'max:'.self::MAX_OCCURRENCES],
            'dates.*' => ['required', 'date_format:Y-m-d'],
            'weekdays' => ['required_if:mode,weekly', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'range_start' => ['required_if:mode,weekly', 'nullable', 'date_format:Y-m-d'],
            'range_end' => ['required_if:mode,weekly', 'nullable', 'date_format:Y-m-d', 'after_or_equal:range_start'],
        ], [
            'dates.required_if' => 'Adicione ao menos uma data específica.',
            'weekdays.required_if' => 'Selecione ao menos um dia da semana.',
            'range_start.required_if' => 'Informe a data inicial da agenda semanal.',
            'range_end.required_if' => 'Informe a data final da agenda semanal.',
        ])->validate();

        $dates = $data['mode'] === 'weekly'
            ? $this->weeklyDates($data)
            : array_values(array_unique($data['dates']));

        if (count($dates) > self::MAX_OCCURRENCES) {
            throw ValidationException::withMessages([
                'dates' => ['A operação pode criar no máximo '.self::MAX_OCCURRENCES.' ocorrências por vez. Reduza o período.'],
            ]);
        }

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $sourceStart = Carbon::parse($source->start_date, $timezone);
        $sourceDate = $sourceStart->format('Y-m-d');
        $startClock = $sourceStart->format('H:i:s');

        foreach ($dates as $date) {
            if ($date === $sourceDate) {
                throw ValidationException::withMessages([
                    'dates' => ['A agenda inclui a data do evento original ('.$date.'). Remova essa data para não duplicar a edição atual.'],
                ]);
            }

            $targetStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startClock, $timezone);
            if ($targetStart->lte($now)) {
                throw ValidationException::withMessages([
                    'dates' => ['Todas as ocorrências precisam começar no futuro. Revise a data '.$date.'.'],
                ]);
            }
        }

        sort($dates);
        $events = [];
        $createdCount = 0;
        $existingCount = 0;
        $processedCount = 0;
        $totalCount = count($dates);

        $this->emitProgress($onProgress, [
            'status' => 'started',
            'processed_count' => 0,
            'total_count' => $totalCount,
            'remaining_count' => $totalCount,
            'created_count' => 0,
            'existing_count' => 0,
            'date' => null,
            'event_id' => null,
        ]);

        foreach ($dates as $date) {
            $targetStart = Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$startClock, $timezone);
            $existing = Event::query()
                ->where('app_id', $appId)
                ->where('production_id', $source->production_id)
                ->where('title', $source->title)
                ->where('start_date', $targetStart->format('Y-m-d H:i:s'))
                ->first();

            if ($existing) {
                $existing->load('production:id,app_id,name,slug,user_id,app_slug');
                $existing->loadCount(['tickets' => fn ($query) => $query->where('app_id', $appId)]);
                $events[] = $existing;
                $existingCount++;
                $processedCount++;

                $this->emitProgress($onProgress, [
                    'status' => 'existing',
                    'processed_count' => $processedCount,
                    'total_count' => $totalCount,
                    'remaining_count' => max(0, $totalCount - $processedCount),
                    'created_count' => $createdCount,
                    'existing_count' => $existingCount,
                    'date' => $date,
                    'event_id' => $existing->id,
                ]);
                continue;
            }

            $created = $this->duplicator->duplicate($source, $date, $appId, $appSlug);
            $events[] = $created;
            $createdCount++;
            $processedCount++;

            $this->emitProgress($onProgress, [
                'status' => 'created',
                'processed_count' => $processedCount,
                'total_count' => $totalCount,
                'remaining_count' => max(0, $totalCount - $processedCount),
                'created_count' => $createdCount,
                'existing_count' => $existingCount,
                'date' => $date,
                'event_id' => $created->id,
            ]);
        }

        return [
            'message' => $createdCount > 0
                ? $createdCount.' ocorrência(s) criada(s) como rascunho.'
                : 'Todas as ocorrências selecionadas já existiam.',
            'mode' => $data['mode'],
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'processed_count' => $processedCount,
            'total_count' => $totalCount,
            'remaining_count' => max(0, $totalCount - $processedCount),
            'target_dates' => $dates,
            'events' => $events,
        ];
    }

    private function emitProgress(?callable $onProgress, array $progress): void
    {
        if ($onProgress !== null) {
            $onProgress($progress);
        }
    }

    private function weeklyDates(array $data): array
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::createFromFormat('Y-m-d', $data['range_start'], $timezone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $data['range_end'], $timezone)->startOfDay();

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'range_end' => ['A agenda semanal pode abranger no máximo '.self::MAX_RANGE_DAYS.' dias por operação.'],
            ]);
        }

        $weekdays = array_map('intval', $data['weekdays']);
        $dates = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            if (in_array($cursor->dayOfWeek, $weekdays, true)) {
                $dates[] = $cursor->format('Y-m-d');
            }
        }

        if ($dates === []) {
            throw ValidationException::withMessages([
                'weekdays' => ['Nenhuma ocorrência dos dias escolhidos existe dentro do período informado.'],
            ]);
        }

        return $dates;
    }
}
