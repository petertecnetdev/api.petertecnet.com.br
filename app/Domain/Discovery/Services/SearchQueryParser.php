<?php

namespace App\Domain\Discovery\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class SearchQueryParser
{
    private const SYNONYMS = [
        'balada' => ['festa', 'evento', 'night'],
        'festinha' => ['festa', 'evento'],
        'pub' => ['bar', 'casa noturna'],
        'boate' => ['balada', 'festa', 'night'],
        'eletronica' => ['eletronico', 'eletro', 'edm'],
        'eletronico' => ['eletronica', 'eletro', 'edm'],
        'sertanejo' => ['sertaneja', 'country'],
        'rock' => ['rock n roll', 'rocknroll'],
        'gratis' => ['gratuito', 'free', 'cortesia'],
        'gratuito' => ['gratis', 'free', 'cortesia'],
        'hoje' => ['today'],
        'amanha' => ['tomorrow'],
    ];

    private const WEEKDAYS = [
        'domingo' => Carbon::SUNDAY,
        'segunda' => Carbon::MONDAY,
        'segunda-feira' => Carbon::MONDAY,
        'terca' => Carbon::TUESDAY,
        'terça' => Carbon::TUESDAY,
        'terca-feira' => Carbon::TUESDAY,
        'terça-feira' => Carbon::TUESDAY,
        'quarta' => Carbon::WEDNESDAY,
        'quarta-feira' => Carbon::WEDNESDAY,
        'quinta' => Carbon::THURSDAY,
        'quinta-feira' => Carbon::THURSDAY,
        'sexta' => Carbon::FRIDAY,
        'sexta-feira' => Carbon::FRIDAY,
        'sabado' => Carbon::SATURDAY,
        'sábado' => Carbon::SATURDAY,
    ];

    public function parse(array $input): array
    {
        $raw = trim((string) ($input['q'] ?? ''));
        $normalized = $this->normalize($raw);
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);

        $hashtags = collect();
        preg_match_all('/#([\pL\pN_\-]+)/u', $raw, $hashtagMatches);
        if (! empty($hashtagMatches[1])) {
            $hashtags = collect($hashtagMatches[1])->map(fn ($value) => $this->normalize($value))->filter()->values();
        }

        $terms = collect(preg_split('/\s+/u', $normalized) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        $filters = [
            'city' => $this->nullableTrim($input['city'] ?? null),
            'uf' => $this->nullableUpper($input['uf'] ?? null),
            'period' => $input['period'] ?? null,
            'date_from' => $input['from'] ?? null,
            'date_to' => $input['to'] ?? null,
            'free' => $this->toBool($input['free'] ?? null),
            'available' => $this->toBool($input['available'] ?? null),
            'format' => $this->nullableTrim($input['format'] ?? null),
            'max_price' => isset($input['max_price']) && $input['max_price'] !== '' ? (float) $input['max_price'] : null,
            'min_price' => isset($input['min_price']) && $input['min_price'] !== '' ? (float) $input['min_price'] : null,
            'lat' => isset($input['lat']) && $input['lat'] !== '' ? (float) $input['lat'] : null,
            'lng' => isset($input['lng']) && $input['lng'] !== '' ? (float) $input['lng'] : null,
            'radius_km' => isset($input['radius_km']) && $input['radius_km'] !== '' ? (int) $input['radius_km'] : null,
            'sort' => $input['sort'] ?? null,
            'category' => $this->nullableTrim($input['category'] ?? null),
            'artist_id' => isset($input['artist_id']) ? (int) $input['artist_id'] : null,
            'production_id' => isset($input['production_id']) ? (int) $input['production_id'] : null,
        ];

        if (! $filters['period']) {
            if ($terms->contains('hoje')) {
                $filters['period'] = 'today';
            } elseif ($terms->contains('amanha')) {
                $filters['period'] = 'tomorrow';
            } elseif ($terms->contains('fim') && $terms->contains('semana')) {
                $filters['period'] = 'weekend';
            } else {
                foreach (self::WEEKDAYS as $label => $weekday) {
                    if (! $terms->contains($this->normalize($label))) {
                        continue;
                    }

                    $day = $now->dayOfWeek === $weekday ? $now->copy() : $now->copy()->next($weekday);
                    $filters['date_from'] = $day->copy()->startOfDay()->toDateString();
                    $filters['date_to'] = $day->copy()->endOfDay()->toDateString();
                    break;
                }
            }
        }

        if ($filters['free'] === null && $terms->intersect(['gratis', 'gratuito', 'free', 'cortesia'])->isNotEmpty()) {
            $filters['free'] = true;
        }

        if (! $filters['format']) {
            if ($terms->contains('online')) {
                $filters['format'] = 'online';
            } elseif ($terms->contains('hibrido') || $terms->contains('híbrido')) {
                $filters['format'] = 'hybrid';
            } elseif ($terms->contains('presencial')) {
                $filters['format'] = 'in_person';
            }
        }

        if ($filters['max_price'] === null) {
            if (preg_match('/(?:ate|até|menos\s+de|max(?:imo)?|r\$)\s*([0-9]+(?:[\.,][0-9]{1,2})?)/iu', $raw, $price)) {
                $filters['max_price'] = (float) str_replace(',', '.', $price[1]);
            }
        }

        if ($filters['radius_km'] === null && preg_match('/([0-9]{1,3})\s*km\b/iu', $raw, $radius)) {
            $filters['radius_km'] = min(500, max(1, (int) $radius[1]));
        }

        if (! $filters['city'] && preg_match('/\bem\s+([\pL][\pL\s\-]{2,50})(?:\s+(?:hoje|amanh[ãa]|sexta|sabado|sábado|domingo|com|ate|até|por)|$)/iu', $raw, $city)) {
            $candidate = trim($city[1]);
            if ($candidate !== '') {
                $filters['city'] = $candidate;
            }
        }

        $controlTokens = collect([
            'hoje', 'amanha', 'fim', 'semana', 'gratis', 'gratuito', 'free', 'cortesia',
            'online', 'hibrido', 'presencial', 'ate', 'menos', 'de', 'km', 'em',
            'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo',
        ]);

        $searchTerms = $terms
            ->reject(fn ($term) => $controlTokens->contains($term))
            ->filter(fn ($term) => ! preg_match('/^[0-9]+(?:[\.,][0-9]+)?$/', $term))
            ->values();

        $expanded = $searchTerms
            ->flatMap(function ($term) {
                $synonyms = self::SYNONYMS[$term] ?? [];

                return array_merge([$term], $synonyms);
            })
            ->merge($hashtags)
            ->map(fn ($term) => $this->normalize($term))
            ->filter()
            ->unique()
            ->values();

        $searchText = trim($searchTerms->implode(' '));
        if ($searchText === '') {
            $searchText = $normalized;
        }

        return [
            'raw' => $raw,
            'normalized' => $normalized,
            'search_text' => $searchText,
            'terms' => $searchTerms->all(),
            'expanded_terms' => $expanded->all(),
            'hashtags' => $hashtags->all(),
            'filters' => $filters,
        ];
    }

    public function normalize(string $value): string
    {
        $value = Str::ascii(Str::lower(trim($value)));
        $value = preg_replace('/[@#]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\s\-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = strtoupper(trim((string) ($value ?? '')));

        return $value !== '' ? $value : null;
    }

    private function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
