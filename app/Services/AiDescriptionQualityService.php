<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiDescriptionQualityService
{
    private const LOW_VALUE_PHRASES = [
        'confira as informacoes disponiveis',
        'acompanhe as atualizacoes',
        'organize sua participacao',
        'confira os detalhes disponiveis',
        'consulte as informacoes apresentadas',
        'evento imperdivel',
        'experiencia inesquecivel',
        'nao perca',
        'garanta ja',
        'prepare se',
    ];

    public function isLowValue(string $value): bool
    {
        $normalized = $this->normalize($value);
        if ($normalized === '') return true;

        $hits = 0;
        foreach (self::LOW_VALUE_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) $hits++;
        }

        return $hits >= 1 && str_word_count($normalized) < 45;
    }

    public function evaluate(string $candidate, string $draft, array $context, array $historicalTexts = []): array
    {
        $candidate = trim($candidate);
        $draft = trim($draft);
        $words = $this->words($candidate);
        $wordCount = count($words);
        $paragraphs = array_values(array_filter(preg_split('/\n\s*\n+/u', $candidate) ?: []));
        $issues = [];

        $scores = [
            'fidelity' => 100,
            'richness' => 100,
            'originality' => 100,
            'clarity' => 100,
            'persuasion' => 100,
            'non_repetition' => 100,
        ];

        if ($wordCount < 45) {
            $scores['richness'] -= 40;
            $issues[] = 'texto curto demais para aproveitar o contexto disponível';
        } elseif ($wordCount < 70) {
            $scores['richness'] -= 18;
            $issues[] = 'texto ainda pouco desenvolvido';
        } elseif ($wordCount > 220) {
            $scores['clarity'] -= 15;
            $issues[] = 'texto longo demais para leitura rápida no celular';
        }

        if (count($paragraphs) < 2) {
            $scores['clarity'] -= 20;
            $issues[] = 'faltam parágrafos curtos e escaneáveis';
        } elseif (count($paragraphs) > 5) {
            $scores['clarity'] -= 10;
            $issues[] = 'há parágrafos demais';
        }

        $normalizedCandidate = $this->normalize($candidate);
        $lowValueHits = 0;
        foreach (self::LOW_VALUE_PHRASES as $phrase) {
            if (str_contains($normalizedCandidate, $phrase)) $lowValueHits++;
        }
        if ($lowValueHits > 0) {
            $scores['persuasion'] -= min(50, $lowValueHits * 18);
            $scores['non_repetition'] -= min(45, $lowValueHits * 15);
            $issues[] = 'usa frases genéricas ou clichês de baixo valor';
        }

        $sentences = array_values(array_filter(array_map(
            'trim',
            preg_split('/(?<=[.!?])\s+/u', preg_replace('/\s+/u', ' ', $candidate) ?: $candidate) ?: []
        )));
        $seen = [];
        foreach ($sentences as $sentence) {
            $key = $this->normalize($sentence);
            if ($key === '') continue;
            if (isset($seen[$key])) {
                $scores['non_repetition'] -= 30;
                $issues[] = 'repete a mesma frase dentro da descrição';
                break;
            }
            $seen[$key] = true;
        }

        $maxSimilarity = 0.0;
        $maxOpeningSimilarity = 0.0;
        foreach ($historicalTexts as $historical) {
            $historical = trim((string) $historical);
            if ($historical === '') continue;
            $maxSimilarity = max($maxSimilarity, $this->jaccardSimilarity($candidate, $historical));
            $maxOpeningSimilarity = max(
                $maxOpeningSimilarity,
                $this->jaccardSimilarity(mb_substr($candidate, 0, 220), mb_substr($historical, 0, 220))
            );
        }

        if ($maxSimilarity >= 0.62) {
            $scores['originality'] -= 45;
            $scores['non_repetition'] -= 20;
            $issues[] = 'está muito parecido com uma descrição anterior da mesma produção';
        } elseif ($maxSimilarity >= 0.48) {
            $scores['originality'] -= 24;
            $issues[] = 'repete bastante o vocabulário de eventos anteriores';
        } elseif ($maxSimilarity >= 0.36) {
            $scores['originality'] -= 10;
        }

        if ($maxOpeningSimilarity >= 0.58) {
            $scores['originality'] -= 25;
            $issues[] = 'a abertura está muito parecida com uma abertura já usada';
        }

        if ($draft !== '' && ! $this->isLowValue($draft)) {
            $draftKeywords = $this->keywords($draft);
            $candidateKeywords = $this->keywords($candidate);
            if (count($draftKeywords) >= 3) {
                $preserved = count(array_intersect($draftKeywords, $candidateKeywords)) / max(1, count($draftKeywords));
                if ($preserved < 0.28) {
                    $scores['fidelity'] -= 28;
                    $issues[] = 'a intenção do rascunho do produtor foi pouco preservada';
                } elseif ($preserved < 0.45) {
                    $scores['fidelity'] -= 12;
                }
            }
        }

        $factValues = [];
        foreach ($context as $key => $value) {
            if (str_starts_with((string) $key, 'historical_style_')) continue;
            if (str_starts_with((string) $key, 'editorial_')) continue;
            if ((string) $key === 'event_items') continue;
            if (is_scalar($value)) $factValues[] = (string) $value;
        }
        $knownFacts = $this->normalize(implode(' ', $factValues));
        preg_match_all('/R\$\s*[0-9]+(?:[.,][0-9]{1,2})?/iu', $candidate, $moneyMatches);
        foreach ($moneyMatches[0] ?? [] as $money) {
            if (! str_contains($knownFacts, $this->normalize($money))) {
                $scores['fidelity'] -= 35;
                $issues[] = 'contém valor monetário que não consta nos dados atuais';
                break;
            }
        }

        $creativeTerms = [
            'musica', 'dj', 'banda', 'show', 'pista', 'danca', 'cerveja', 'energetico',
            'rosh', 'drinks', 'bebida', 'comida', 'open bar', 'dois ambientes',
        ];
        foreach ($creativeTerms as $term) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/', $normalizedCandidate)
                && ! preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/', $knownFacts)
                && ! preg_match('/(?<![a-z0-9])' . preg_quote($term, '/') . '(?![a-z0-9])/', $this->normalize($draft))) {
                $scores['fidelity'] -= 28;
                $issues[] = 'inclui elemento de experiência que não está confirmado no evento atual';
                break;
            }
        }

        if ((str_contains($normalizedCandidate, 'no coracao de') || str_contains($normalizedCandidate, 'bem no centro de'))
            && ! str_contains($knownFacts, 'no coracao de')
            && ! str_contains($knownFacts, 'bem no centro de')) {
            $scores['fidelity'] -= 20;
            $issues[] = 'atribui uma localização qualitativa não confirmada';
        }

        $artistFacts = $this->normalize((string) ($context['artists'] ?? ''));
        $draftFacts = $this->normalize($draft);
        if (preg_match('/\b(dj|banda|cantor|cantora|show|atra[cç][aã]o)\b/iu', $candidate)) {
            if ($artistFacts === '' && ! preg_match('/\b(dj|banda|cantor|cantora|show|atra[cç][aã]o)\b/iu', $draftFacts)) {
                $scores['fidelity'] -= 30;
                $issues[] = 'menciona atração ou artista sem confirmação no evento atual';
            }
        }

        foreach ($scores as $key => $score) {
            $scores[$key] = max(0, min(100, (int) round($score)));
        }

        $overall = (int) round(
            $scores['fidelity'] * 0.26 +
            $scores['richness'] * 0.16 +
            $scores['originality'] * 0.18 +
            $scores['clarity'] * 0.14 +
            $scores['persuasion'] * 0.12 +
            $scores['non_repetition'] * 0.14
        );

        return [
            'score' => max(0, min(100, $overall)),
            'scores' => $scores,
            'issues' => array_values(array_unique($issues)),
            'max_history_similarity' => round($maxSimilarity, 3),
            'max_opening_similarity' => round($maxOpeningSimilarity, 3),
            'word_count' => $wordCount,
            'paragraph_count' => count($paragraphs),
            'passes' => $overall >= 72
                && $scores['fidelity'] >= 72
                && $scores['originality'] >= 60
                && $scores['non_repetition'] >= 60,
        ];
    }

    private function jaccardSimilarity(string $left, string $right): float
    {
        $a = array_values(array_unique($this->keywords($left)));
        $b = array_values(array_unique($this->keywords($right)));
        if ($a === [] || $b === []) return 0.0;
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function keywords(string $value): array
    {
        $stop = array_flip([
            'a','o','as','os','de','da','do','das','dos','e','em','na','no','nas','nos','para','por',
            'com','um','uma','uns','umas','que','se','ao','aos','sua','seu','suas','seus','mais','muito',
            'muita','muitos','muitas','dia','noite','evento','esta','este','essa','esse','ja','tambem',
        ]);
        $words = $this->words($this->normalize($value));
        return array_values(array_filter($words, fn ($word) => mb_strlen($word) >= 4 && ! isset($stop[$word])));
    }

    private function words(string $value): array
    {
        $value = $this->normalize($value);
        if ($value === '') return [];
        return array_values(array_filter(preg_split('/\s+/u', $value) ?: []));
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower(strip_tags($value)));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
