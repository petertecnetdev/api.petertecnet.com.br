<?php

namespace App\Domain\EventDiscovery\Services;

use InvalidArgumentException;

final class DiscoverySourcePolicy
{
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'], $parts['pass'])
            || isset($parts['user'])
            || isset($parts['port'])
            || filter_var($host, FILTER_VALIDATE_IP)
            || ! in_array($host, array_map('strtolower', (array) config('event_discovery.allowed_hosts', [])), true)
        ) {
            throw new InvalidArgumentException('Fonte HTTPS não autorizada. Configure o domínio explicitamente.');
        }

        // Domínios privados ou locais não podem ser usados como ponte para a rede interna.
        $addresses = gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            throw new InvalidArgumentException('O domínio da fonte não possui DNS IPv4 público verificável.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Endereço de rede interno ou reservado não permitido.');
            }
        }
    }
}
