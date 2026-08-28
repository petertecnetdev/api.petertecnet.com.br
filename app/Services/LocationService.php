<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LocationService
{
    public function fromIp(?string $ip): array
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['city' => null, 'uf' => null];
        }

        return Cache::remember('geo_ip:' . hash('sha256', $ip), now()->addHours(12), function () use ($ip) {
            try {
                $template = (string) config('services.geo_ip.endpoint', 'https://ipwho.is/{ip}');
                $url = str_replace('{ip}', rawurlencode($ip), $template);

                $response = Http::acceptJson()
                    ->timeout(2)
                    ->retry(1, 100)
                    ->get($url);

                if (! $response->successful()) {
                    return ['city' => null, 'uf' => null];
                }

                $payload = $response->json();
                if (! is_array($payload) || ($payload['success'] ?? true) === false) {
                    return ['city' => null, 'uf' => null];
                }

                return [
                    'city' => $payload['city'] ?? null,
                    'uf' => $payload['region_code'] ?? $payload['region'] ?? null,
                ];
            } catch (\Throwable $e) {
                report($e);

                return ['city' => null, 'uf' => null];
            }
        });
    }
}
