<?php

namespace App\Services\Operations;

use Illuminate\Support\Str;

class OperationalIssueClassifier
{
    public function category(array $event): string
    {
        $status = isset($event['http_status']) ? (int) $event['http_status'] : null;
        $message = Str::lower((string) ($event['message'] ?? ''));
        $errorCode = Str::lower((string) ($event['error_code'] ?? ''));
        $outcome = Str::lower((string) ($event['outcome'] ?? ''));

        if ($status === 401) return 'authentication';
        if ($status === 403) return 'authorization';
        if ($status === 422) return 'validation';
        if ($status === 429) return 'rate_limit';
        if ($status === 409) return 'business_rule';

        if ($this->containsAny($message.' '.$errorCode, ['sqlstate', 'queryexception', 'deadlock', 'database', 'pdoexception', 'constraint violation'])) {
            return 'database';
        }
        if ($this->containsAny($message, ['timed out', 'timeout', 'maximum execution time'])) {
            return 'timeout';
        }
        if ($this->containsAny($message, ['connection refused', 'could not resolve host', 'service unavailable', 'bad gateway', 'gateway timeout', 'curl error', 'dns'])) {
            return 'dependency';
        }
        if ($status !== null && $status >= 500) return 'server_exception';
        if (in_array($outcome, ['denied', 'refused'], true)) return 'access_control';
        if ($outcome === 'error') return 'application_error';
        if (($event['severity'] ?? null) === 'suspicious') return 'security';

        return 'operational';
    }

    public function domain(array $event): string
    {
        $source = Str::lower(implode(' ', array_filter([
            $event['route_name'] ?? null,
            $event['route'] ?? null,
            $event['path'] ?? null,
            $event['frontend_page'] ?? null,
        ])));

        $domains = [
            'payments' => ['payment', 'payments', 'pix', 'checkout', 'refund', 'charge', 'billing'],
            'commerce' => ['order', 'orders', 'cart', 'commerce', 'purchase'],
            'scheduling' => ['appointment', 'appointments', 'schedule', 'availability', 'booking'],
            'establishments' => ['establishment', 'establishments', 'company', 'business'],
            'catalog' => ['item', 'items', 'catalog', 'menu', 'product', 'service'],
            'identity' => ['auth', 'login', 'logout', 'password', 'invite', 'user', 'profile'],
            'notifications' => ['notification', 'notifications', 'email', 'mail'],
            'events' => ['event', 'events', 'ticket', 'tickets', 'production'],
            'files' => ['file', 'files', 'storage', 'upload', 'media'],
            'runtime' => ['queue', 'queues', 'job', 'jobs', 'scheduler', 'backup', 'heartbeat'],
            'operations' => ['admin/ecosystem', 'command', 'incident', 'diagnostic'],
        ];

        foreach ($domains as $domain => $needles) {
            if ($this->containsAny($source, $needles)) return $domain;
        }

        return 'platform';
    }

    public function impactScore(array $event, int $occurrences = 1, int $users = 0, int $applications = 0): int
    {
        $score = match ($event['severity'] ?? 'normal') {
            'critical' => 45,
            'suspicious' => 30,
            'attention' => 15,
            default => 5,
        };

        $outcome = Str::lower((string) ($event['outcome'] ?? ''));
        if ($outcome === 'error') $score += 20;
        if (in_array($outcome, ['denied', 'refused'], true)) $score += 8;

        $status = isset($event['http_status']) ? (int) $event['http_status'] : null;
        if ($status !== null && $status >= 500) $score += 25;
        elseif ($status !== null && $status >= 400) $score += 5;

        $category = $event['category'] ?? $this->category($event);
        if (in_array($category, ['database', 'dependency', 'timeout'], true)) $score += 10;

        $domain = $event['domain'] ?? $this->domain($event);
        if ($domain === 'payments') $score += 10;

        $score += min(20, (int) ceil(log10(max(1, $occurrences)) * 8));
        $score += min(10, $users);
        $score += min(10, $applications * 2);

        return min(100, max(0, $score));
    }

    public function priority(int $impactScore): string
    {
        return match (true) {
            $impactScore >= 80 => 'P0',
            $impactScore >= 60 => 'P1',
            $impactScore >= 40 => 'P2',
            default => 'P3',
        };
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($haystack, $needle)) return true;
        }

        return false;
    }
}
