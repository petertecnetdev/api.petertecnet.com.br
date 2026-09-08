<?php

namespace App\Domain\Events\Services;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class TicketSalesCutoffService
{
    public const MODE_AT_START = 'at_start';
    public const MODE_BEFORE_START = 'before_start';
    public const MODE_AFTER_START = 'after_start';
    public const MODE_BEFORE_END = 'before_end';

    public const MODES = [
        self::MODE_AT_START,
        self::MODE_BEFORE_START,
        self::MODE_AFTER_START,
        self::MODE_BEFORE_END,
    ];

    private const MAX_OFFSET_MINUTES = 525600;

    public function ruleFor(array $data, ?Ticket $sourceTicket, Event $referenceEvent): array
    {
        if ($sourceTicket) {
            return $this->ruleFromTicket($sourceTicket);
        }

        $requestedMode = trim((string) ($data['sales_cutoff_mode'] ?? ''));
        if ($requestedMode !== '') {
            return $this->normalizeRule(
                $requestedMode,
                (int) ($data['sales_cutoff_offset_minutes'] ?? 0)
            );
        }

        // Backward compatibility for clients that still submit one absolute limit_date.
        // Convert it into a relative rule using the first selected event so the same
        // rule can then be projected safely onto every target event.
        if (! empty($data['limit_date'])) {
            return $this->inferRuleFromDate(
                $referenceEvent,
                Carbon::parse($data['limit_date'], $this->timezone())
            );
        }

        return $this->normalizeRule(self::MODE_AT_START, 0);
    }

    public function ruleFromTicket(Ticket $ticket): array
    {
        $mode = trim((string) $ticket->sales_cutoff_mode);
        if (in_array($mode, self::MODES, true)) {
            return $this->normalizeRule(
                $mode,
                (int) ($ticket->sales_cutoff_offset_minutes ?? 0)
            );
        }

        if ($ticket->limit_date && $ticket->event) {
            return $this->inferRuleFromDate(
                $ticket->event,
                Carbon::parse($ticket->limit_date, $this->timezone())
            );
        }

        // Legacy tickets without an explicit deadline become safe by default when reused.
        return $this->normalizeRule(self::MODE_AT_START, 0);
    }

    public function cutoffForEvent(Event $event, array $rule): array
    {
        if (! $event->start_date || ! $event->end_date) {
            throw ValidationException::withMessages([
                'sales_cutoff_mode' => ["O evento {$event->title} precisa ter início e término definidos para calcular o encerramento das vendas."],
            ]);
        }

        $start = Carbon::parse($event->start_date, $this->timezone());
        $end = Carbon::parse($event->end_date, $this->timezone());
        if (! $end->gt($start)) {
            throw ValidationException::withMessages([
                'sales_cutoff_mode' => ["O término do evento {$event->title} precisa ser posterior ao início."],
            ]);
        }

        $normalized = $this->normalizeRule(
            (string) ($rule['mode'] ?? self::MODE_AT_START),
            (int) ($rule['offset_minutes'] ?? 0)
        );
        $offset = $normalized['offset_minutes'];

        $cutoff = match ($normalized['mode']) {
            self::MODE_BEFORE_START => $start->copy()->subMinutes($offset),
            self::MODE_AFTER_START => $start->copy()->addMinutes($offset),
            self::MODE_BEFORE_END => $end->copy()->subMinutes($offset),
            default => $start->copy(),
        };

        $clampedToEventEnd = false;
        if ($cutoff->gt($end)) {
            $cutoff = $end->copy();
            $clampedToEventEnd = true;
        }

        if (! $cutoff->gt(Carbon::now($this->timezone()))) {
            throw ValidationException::withMessages([
                'sales_cutoff_mode' => ["O encerramento calculado para {$event->title} já passou. Escolha uma regra que ainda permita vendas antes do término do evento."],
            ]);
        }

        return [
            'limit_date' => $cutoff,
            'clamped_to_event_end' => $clampedToEventEnd,
        ];
    }

    public function normalizeRule(string $mode, int $offsetMinutes): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages([
                'sales_cutoff_mode' => ['Selecione uma regra válida para encerrar as vendas.'],
            ]);
        }

        if ($mode === self::MODE_AT_START) {
            return ['mode' => $mode, 'offset_minutes' => 0];
        }

        if ($offsetMinutes < 1 || $offsetMinutes > self::MAX_OFFSET_MINUTES) {
            throw ValidationException::withMessages([
                'sales_cutoff_offset_minutes' => ['Informe um intervalo entre 1 minuto e 1 ano.'],
            ]);
        }

        return ['mode' => $mode, 'offset_minutes' => $offsetMinutes];
    }

    private function inferRuleFromDate(Event $event, Carbon $limit): array
    {
        if ($event->start_date) {
            $start = Carbon::parse($event->start_date, $this->timezone());
            if (abs($limit->diffInSeconds($start, false)) < 60) {
                return $this->normalizeRule(self::MODE_AT_START, 0);
            }

            if ($limit->lt($start)) {
                return $this->normalizeRule(
                    self::MODE_BEFORE_START,
                    $this->minutesRoundedUp($limit->diffInSeconds($start))
                );
            }

            return $this->normalizeRule(
                self::MODE_AFTER_START,
                $this->minutesRoundedUp($start->diffInSeconds($limit))
            );
        }

        if ($event->end_date) {
            $end = Carbon::parse($event->end_date, $this->timezone());
            if ($limit->lt($end)) {
                return $this->normalizeRule(
                    self::MODE_BEFORE_END,
                    $this->minutesRoundedUp($limit->diffInSeconds($end))
                );
            }
        }

        return $this->normalizeRule(self::MODE_AT_START, 0);
    }

    private function minutesRoundedUp(int|float $seconds): int
    {
        $seconds = max(1, (int) ceil(abs($seconds)));

        return min(self::MAX_OFFSET_MINUTES, max(1, (int) ceil($seconds / 60)));
    }

    private function timezone(): string
    {
        return (string) config('app.timezone', 'America/Sao_Paulo');
    }
}
