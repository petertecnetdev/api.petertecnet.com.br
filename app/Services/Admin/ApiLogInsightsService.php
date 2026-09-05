<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ApiLogInsightsService
{
    private const MAX_BYTES = 8_000_000;
    private const MAX_ENTRIES = 1500;

    public function summarize(string $range = '24h'): array
    {
        $since = CarbonImmutable::now()->sub($this->rangeInterval($range));
        $entries = $this->readEntries($since);
        $groups = $this->groupEntries($entries);
        $total = count($entries);
        $errors = count(array_filter($entries, fn (array $entry) => in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)));
        $warnings = count(array_filter($entries, fn (array $entry) => $entry['level'] === 'WARNING'));

        $byLevel = [];
        $byApplication = [];
        $timeline = [];
        foreach ($entries as $entry) {
            $byLevel[$entry['level']] = ($byLevel[$entry['level']] ?? 0) + 1;
            $app = $entry['application'] ?: 'API Central';
            $byApplication[$app] = ($byApplication[$app] ?? 0) + 1;
            $bucket = CarbonImmutable::parse($entry['timestamp'])->format($range === '1h' ? 'H:i' : 'd/m H:00');
            $timeline[$bucket] = ($timeline[$bucket] ?? 0) + 1;
        }

        arsort($byApplication);
        arsort($byLevel);

        return [
            'generated_at' => now()->toIso8601String(),
            'range' => $range,
            'summary' => [
                'entries' => $total,
                'errors' => $errors,
                'warnings' => $warnings,
                'critical_groups' => count(array_filter($groups, fn (array $group) => $group['severity'] === 'critical')),
                'health_score' => $this->healthScore($total, $errors, $warnings, $groups),
                'health_label' => $this->healthLabel($total, $errors, $warnings),
            ],
            'narrative' => $this->narrative($entries, $groups, $byApplication),
            'applications' => collect($byApplication)->map(fn ($count, $name) => [
                'name' => $name,
                'count' => $count,
                'share' => $total ? round(($count / $total) * 100, 1) : 0,
            ])->values()->take(12)->all(),
            'levels' => collect($byLevel)->map(fn ($count, $level) => ['level' => $level, 'count' => $count])->values()->all(),
            'timeline' => collect($timeline)->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all(),
            'issues' => array_slice($groups, 0, 25),
        ];
    }

    private function readEntries(CarbonImmutable $since): array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $entries = [];

        foreach ($files as $file) {
            if (count($entries) >= self::MAX_ENTRIES) break;
            $content = $this->tail($file, self::MAX_BYTES);
            if ($content === '') continue;

            $chunks = preg_split('/(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/m', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($chunks as $chunk) {
                if (count($entries) >= self::MAX_ENTRIES) break 2;
                $entry = $this->parseChunk($chunk);
                if (!$entry || CarbonImmutable::parse($entry['timestamp'])->lt($since)) continue;
                $entries[] = $entry;
            }
        }

        usort($entries, fn ($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
        return $entries;
    }

    private function tail(string $path, int $bytes): string
    {
        $size = @filesize($path);
        if (!$size) return '';
        $handle = @fopen($path, 'rb');
        if (!$handle) return '';
        $length = min($size, $bytes);
        fseek($handle, -$length, SEEK_END);
        $content = fread($handle, $length) ?: '';
        fclose($handle);
        if ($length < $size) {
            $firstNewline = strpos($content, "\n");
            if ($firstNewline !== false) $content = substr($content, $firstNewline + 1);
        }
        return $content;
    }

    private function parseChunk(string $chunk): ?array
    {
        if (!preg_match('/^\[(?<time>[^\]]+)\]\s+(?<env>[^.\s]+)\.(?<level>[A-Z]+):\s+(?<message>.*)$/s', trim($chunk), $match)) return null;
        $message = trim($match['message']);
        $firstLine = trim(Str::before($message, "\n"));
        $sanitized = $this->sanitize($message);
        $application = $this->detectApplication($sanitized);
        $exception = null;
        if (preg_match('/([A-Za-z0-9_\\]+Exception|[A-Za-z0-9_\\]+Error)(?::|\s)/', $firstLine, $exceptionMatch)) {
            $exception = class_basename(str_replace('\\\\', '\\', $exceptionMatch[1]));
        }
        $status = null;
        if (preg_match('/(?:status|HTTP)["\'\s:=]+([45]\d{2})/i', $firstLine, $statusMatch)) $status = (int) $statusMatch[1];

        return [
            'timestamp' => CarbonImmutable::parse($match['time'])->toIso8601String(),
            'environment' => $match['env'],
            'level' => $match['level'],
            'message' => Str::limit($this->friendlyMessage($firstLine), 360),
            'exception' => $exception,
            'http_status' => $status,
            'application' => $application,
            'fingerprint' => $this->fingerprint($firstLine, $exception),
            'technical_excerpt' => Str::limit($sanitized, 5000),
        ];
    }

    private function groupEntries(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = $entry['fingerprint'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'fingerprint' => $key,
                    'title' => $entry['exception'] ?: $entry['message'],
                    'explanation' => $this->explain($entry),
                    'severity' => $this->severity($entry),
                    'level' => $entry['level'],
                    'count' => 0,
                    'applications' => [],
                    'first_seen' => $entry['timestamp'],
                    'last_seen' => $entry['timestamp'],
                    'sample' => $entry,
                ];
            }
            $groups[$key]['count']++;
            $app = $entry['application'] ?: 'API Central';
            $groups[$key]['applications'][$app] = ($groups[$key]['applications'][$app] ?? 0) + 1;
            if ($entry['timestamp'] < $groups[$key]['first_seen']) $groups[$key]['first_seen'] = $entry['timestamp'];
            if ($entry['timestamp'] > $groups[$key]['last_seen']) $groups[$key]['last_seen'] = $entry['timestamp'];
        }

        $result = array_values($groups);
        foreach ($result as &$group) {
            arsort($group['applications']);
            $group['applications'] = collect($group['applications'])->map(fn ($count, $name) => ['name' => $name, 'count' => $count])->values()->take(5)->all();
            $group['impact'] = $this->impact($group);
        }
        unset($group);

        usort($result, function ($a, $b) {
            $weight = ['critical' => 4, 'high' => 3, 'attention' => 2, 'normal' => 1];
            return (($weight[$b['severity']] ?? 0) * 100000 + $b['count']) <=> (($weight[$a['severity']] ?? 0) * 100000 + $a['count']);
        });
        return $result;
    }

    private function narrative(array $entries, array $groups, array $byApplication): array
    {
        $total = count($entries);
        if (!$total) return ['Nenhum registro relevante foi encontrado no período selecionado.'];
        $messages = [];
        $topApp = array_key_first($byApplication);
        $topCount = $topApp ? $byApplication[$topApp] : 0;
        if ($topApp) $messages[] = sprintf('%s concentrou %s%% dos registros relevantes (%d de %d).', $topApp, round(($topCount / $total) * 100, 1), $topCount, $total);
        $critical = collect($groups)->first(fn ($group) => $group['severity'] === 'critical');
        if ($critical) $messages[] = sprintf('O ponto mais crítico é “%s”, repetido %d vezes; priorize este grupo porque combina severidade alta com recorrência.', $critical['title'], $critical['count']);
        $repeated = collect($groups)->sortByDesc('count')->first();
        if ($repeated && (!$critical || $repeated['fingerprint'] !== $critical['fingerprint'])) $messages[] = sprintf('O problema mais recorrente apareceu %d vezes: “%s”. Agrupar por assinatura evita tratar cada linha do log como um incidente diferente.', $repeated['count'], $repeated['title']);
        return $messages;
    }

    private function detectApplication(string $text): ?string
    {
        $map = [
            'cutinapp' => 'Cutinapp', 'kryvion' => 'Kryvion', 'crivion' => 'Kryvion', 'nexus' => 'Nexus',
            'rasoio' => 'Rasoio', 'plat' => 'Plat', 'inkap' => 'Inkap', 'payflow' => 'PayFlow',
            'laora' => 'Laora', 'locaio' => 'Locaio', 'admin center' => 'Admin Center', 'petertecnet' => 'Peter Tecnet',
        ];
        $lower = Str::lower($text);
        foreach ($map as $needle => $label) if (str_contains($lower, $needle)) return $label;
        if (preg_match('/app(?:lication)?[_\s.-]*(?:name|slug|id)["\'\s:=]+([a-z0-9_-]+)/i', $text, $match)) return Str::headline($match[1]);
        return null;
    }

    private function sanitize(string $text): string
    {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [REDACTED]', $text) ?? $text;
        $text = preg_replace('/(["\']?(?:password|token|secret|authorization|api[_-]?key)["\']?\s*[:=]\s*)["\']?[^,\s}\]]+/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/\b[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}\b/', '[EMAIL]', $text) ?? $text;
        return $text;
    }

    private function fingerprint(string $message, ?string $exception): string
    {
        $normalized = Str::lower($message);
        $normalized = preg_replace('/\b\d+\b/', '#', $normalized) ?? $normalized;
        $normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27,}/i', '{uuid}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return substr(hash('sha256', ($exception ?: '') . '|' . Str::limit($normalized, 800, '')), 0, 20);
    }

    private function friendlyMessage(string $message): string
    {
        $message = preg_replace('/\{.*$/s', '', $message) ?? $message;
        return trim($message);
    }

    private function explain(array $entry): string
    {
        if ($entry['http_status'] === 429 || str_contains(Str::lower($entry['message']), 'too many requests')) return 'A API está recusando chamadas por limite de requisições. Isso normalmente indica throttling inadequado para o volume real, repetição excessiva no frontend ou ausência de cache/batch.';
        if ($entry['http_status'] >= 500 || in_array($entry['level'], ['CRITICAL', 'ALERT', 'EMERGENCY'], true)) return 'A falha ocorreu no lado do servidor e pode interromper fluxos do usuário. O agrupamento abaixo mostra onde ela se repete para facilitar a priorização.';
        if (str_contains(Str::lower($entry['message']), 'sql') || str_contains(Str::lower($entry['message']), 'query')) return 'O registro aponta para acesso a banco de dados. Vale verificar consulta, índice, conexão e integridade dos dados relacionados.';
        if (str_contains(Str::lower($entry['message']), 'timeout')) return 'A operação ultrapassou o tempo esperado. Isso pode representar dependência externa lenta, consulta pesada ou saturação de recursos.';
        if ($entry['level'] === 'WARNING') return 'É um alerta que não necessariamente derrubou a requisição, mas pode indicar degradação, comportamento inesperado ou risco de falha futura.';
        return 'Registro operacional agrupado por assinatura para mostrar recorrência e impacto sem exigir leitura manual do arquivo de log.';
    }

    private function severity(array $entry): string
    {
        if (in_array($entry['level'], ['EMERGENCY', 'ALERT', 'CRITICAL'], true) || ($entry['http_status'] ?? 0) >= 500) return 'critical';
        if ($entry['level'] === 'ERROR' || ($entry['http_status'] ?? 0) === 429) return 'high';
        if ($entry['level'] === 'WARNING') return 'attention';
        return 'normal';
    }

    private function impact(array $group): string
    {
        $apps = collect($group['applications'])->pluck('name')->take(3)->implode(', ');
        return sprintf('%d ocorrência%s%s%s.', $group['count'], $group['count'] === 1 ? '' : 's', $apps ? ' afetando ' : '', $apps);
    }

    private function healthScore(int $total, int $errors, int $warnings, array $groups): int
    {
        if (!$total) return 100;
        $criticalGroups = count(array_filter($groups, fn ($group) => $group['severity'] === 'critical'));
        $penalty = min(75, (($errors / $total) * 65) + (($warnings / $total) * 20) + min(15, $criticalGroups * 3));
        return max(0, (int) round(100 - $penalty));
    }

    private function healthLabel(int $total, int $errors, int $warnings): string
    {
        if (!$total) return 'Sem ocorrências relevantes';
        $rate = $errors / $total;
        if ($rate >= .25) return 'Crítico';
        if ($rate >= .08) return 'Instável';
        if ($warnings > $errors * 2) return 'Atenção';
        return 'Saudável';
    }

    private function rangeInterval(string $range): \DateInterval
    {
        return match ($range) {
            '1h' => new \DateInterval('PT1H'),
            '7d' => new \DateInterval('P7D'),
            '30d' => new \DateInterval('P30D'),
            default => new \DateInterval('P1D'),
        };
    }
}
