<?php

namespace App\Domain\Discovery\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SearchPerformanceSyncService
{
    public function status(): array
    {
        return [
            'google' => [
                'configured' => (bool) (config('services.search_console.site_url') && (config('services.search_console.access_token') || config('services.search_console.service_account_json'))),
                'site_url' => config('services.search_console.site_url'),
            ],
            'bing' => [
                'configured' => (bool) (config('services.bing_webmaster.site_url') && config('services.bing_webmaster.stats_url') && (config('services.bing_webmaster.access_token') || config('services.bing_webmaster.api_key'))),
                'site_url' => config('services.bing_webmaster.site_url'),
                'requires_rest_endpoint' => ! (bool) config('services.bing_webmaster.stats_url'),
            ],
        ];
    }

    public function sync(?string $provider = null, int $days = 28): array
    {
        $providers = $provider ? [$provider] : ['google', 'bing'];
        $result = [];
        foreach ($providers as $name) {
            try {
                $result[$name] = match ($name) {
                    'google' => ['success' => true, 'imported' => $this->syncGoogle($days)],
                    'bing' => ['success' => true, 'imported' => $this->syncBing($days)],
                    default => throw new RuntimeException('Provedor de busca não suportado.'),
                };
            } catch (\Throwable $e) {
                $result[$name] = ['success' => false, 'imported' => 0, 'message' => $e->getMessage()];
            }
        }
        return $result;
    }

    public function import(string $provider, array $rows, ?int $applicationId = null): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $date = $row['date'] ?? $row['measured_on'] ?? null;
            if (! $date) continue;
            DB::table('search_performance_records')->insert([
                'application_id' => $applicationId,
                'provider' => Str::lower($provider),
                'measured_on' => $date,
                'query' => isset($row['query']) ? mb_substr((string) $row['query'], 0, 500) : null,
                'page' => isset($row['page']) ? mb_substr((string) $row['page'], 0, 1000) : null,
                'device' => isset($row['device']) ? mb_substr((string) $row['device'], 0, 32) : null,
                'country' => isset($row['country']) ? mb_substr((string) $row['country'], 0, 16) : null,
                'clicks' => max(0, (int) ($row['clicks'] ?? 0)),
                'impressions' => max(0, (int) ($row['impressions'] ?? 0)),
                'ctr' => max(0, (float) ($row['ctr'] ?? 0)),
                'position' => isset($row['position']) ? (float) $row['position'] : null,
                'metadata' => isset($row['metadata']) ? json_encode($row['metadata']) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $count++;
        }
        return $count;
    }

    private function syncGoogle(int $days): int
    {
        $siteUrl = (string) config('services.search_console.site_url');
        if (! $siteUrl) throw new RuntimeException('Search Console ainda não possui GOOGLE_SEARCH_CONSOLE_SITE_URL.');
        $token = config('services.search_console.access_token') ?: $this->googleServiceAccountToken();
        if (! $token) throw new RuntimeException('Search Console ainda não possui credencial configurada.');
        $end = now()->subDay()->toDateString();
        $start = now()->subDays(max(2, min($days, 90)))->toDateString();
        $response = Http::withToken($token)->timeout(30)->post(
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query',
            ['startDate' => $start, 'endDate' => $end, 'dimensions' => ['date', 'query', 'page', 'device', 'country'], 'rowLimit' => 25000, 'dataState' => 'final']
        );
        if (! $response->successful()) throw new RuntimeException('Search Console respondeu HTTP ' . $response->status() . '.');
        $rows = collect($response->json('rows', []))->map(function ($row) {
            $keys = $row['keys'] ?? [];
            return ['date' => $keys[0] ?? null, 'query' => $keys[1] ?? null, 'page' => $keys[2] ?? null, 'device' => $keys[3] ?? null, 'country' => $keys[4] ?? null, 'clicks' => $row['clicks'] ?? 0, 'impressions' => $row['impressions'] ?? 0, 'ctr' => $row['ctr'] ?? 0, 'position' => $row['position'] ?? null];
        })->filter(fn ($row) => $row['date'])->all();
        DB::table('search_performance_records')->where('provider', 'google')->whereBetween('measured_on', [$start, $end])->delete();
        return $this->import('google', $rows);
    }

    private function syncBing(int $days): int
    {
        $siteUrl = (string) config('services.bing_webmaster.site_url');
        $statsUrl = (string) config('services.bing_webmaster.stats_url');
        if (! $siteUrl || ! $statsUrl) throw new RuntimeException('Bing Webmaster requer site e endpoint REST atual configurados; endpoints legados não são usados.');
        $request = Http::acceptJson()->timeout(30);
        if ($token = config('services.bing_webmaster.access_token')) $request = $request->withToken($token);
        $query = ['siteUrl' => $siteUrl, 'days' => max(2, min($days, 90))];
        if ($apiKey = config('services.bing_webmaster.api_key')) $query['apikey'] = $apiKey;
        $response = $request->get($statsUrl, $query);
        if (! $response->successful()) throw new RuntimeException('Bing Webmaster respondeu HTTP ' . $response->status() . '.');
        $payload = $response->json();
        $raw = $payload['rows'] ?? $payload['value'] ?? $payload['d'] ?? [];
        $rows = collect($raw)->map(function ($row) {
            $date = $row['Date'] ?? $row['date'] ?? $row['measured_on'] ?? null;
            if (is_string($date) && preg_match('/\/Date\((\d+)/', $date, $match)) $date = date('Y-m-d', ((int) $match[1]) / 1000);
            return ['date' => $date, 'query' => $row['Query'] ?? $row['query'] ?? null, 'page' => $row['Page'] ?? $row['page'] ?? null, 'clicks' => $row['Clicks'] ?? $row['clicks'] ?? 0, 'impressions' => $row['Impressions'] ?? $row['impressions'] ?? 0, 'position' => $row['AvgImpressionPosition'] ?? $row['position'] ?? null, 'ctr' => $row['ctr'] ?? (($row['Impressions'] ?? 0) > 0 ? ($row['Clicks'] ?? 0) / $row['Impressions'] : 0)];
        })->filter(fn ($row) => $row['date'])->all();
        $from = now()->subDays(max(2, min($days, 90)))->toDateString();
        DB::table('search_performance_records')->where('provider', 'bing')->where('measured_on', '>=', $from)->delete();
        return $this->import('bing', $rows);
    }

    private function googleServiceAccountToken(): ?string
    {
        $raw = config('services.search_console.service_account_json');
        if (! $raw) return null;
        $credentials = json_decode($raw, true);
        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) return null;
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(json_encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3500]));
        $signature = '';
        if (! openssl_sign($header . '.' . $claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Não foi possível assinar a credencial do Search Console.');
        $jwt = $header . '.' . $claims . '.' . $this->base64Url($signature);
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
        return $response->successful() ? $response->json('access_token') : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
