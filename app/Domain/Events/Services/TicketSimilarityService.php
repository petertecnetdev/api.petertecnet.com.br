<?php

namespace App\Domain\Events\Services;

use App\Models\Ticket;
use Illuminate\Support\Str;

final class TicketSimilarityService
{
    public const MINIMUM_SCORE = 70;

    public function compare(Ticket $source, Ticket $candidate): array
    {
        $score = 0;
        $reasons = [];

        if ($this->normalize($source->name) === $this->normalize($candidate->name)) {
            $score += 55;
            $reasons[] = 'same_name';
        }

        if ((string) $source->type === (string) $candidate->type) {
            $score += 10;
            $reasons[] = 'same_type';
        }

        if ((string) $source->ticket_type === (string) $candidate->ticket_type) {
            $score += 10;
            $reasons[] = 'same_ticket_type';
        }

        if (abs((float) $source->price - (float) $candidate->price) < 0.005) {
            $score += 10;
            $reasons[] = 'same_price';
        }

        if ((int) $source->quantity === (int) $candidate->quantity) {
            $score += 5;
            $reasons[] = 'same_quantity';
        }

        if (
            (string) $source->sales_cutoff_mode !== ''
            && (string) $source->sales_cutoff_mode === (string) $candidate->sales_cutoff_mode
            && (int) $source->sales_cutoff_offset_minutes === (int) $candidate->sales_cutoff_offset_minutes
        ) {
            $score += 5;
            $reasons[] = 'same_sales_cutoff';
        }

        $sourceDescription = $this->normalize($source->description);
        if ($sourceDescription !== '' && $sourceDescription === $this->normalize($candidate->description)) {
            $score += 5;
            $reasons[] = 'same_description';
        }

        return [
            'score' => min(100, $score),
            'reasons' => $reasons,
            'similar' => $score >= self::MINIMUM_SCORE,
        ];
    }

    private function normalize(mixed $value): string
    {
        return Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish()
            ->toString();
    }
}
