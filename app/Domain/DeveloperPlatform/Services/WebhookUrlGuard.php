<?php

namespace App\Domain\DeveloperPlatform\Services;

use InvalidArgumentException;

class WebhookUrlGuard
{
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Webhooks exigem uma URL HTTPS válida.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Hosts locais não são permitidos para webhooks.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new InvalidArgumentException('Não foi possível resolver o host do webhook.');
        }

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($public === false) {
                throw new InvalidArgumentException('O webhook deve apontar apenas para endereços públicos.');
            }
        }
    }
}
