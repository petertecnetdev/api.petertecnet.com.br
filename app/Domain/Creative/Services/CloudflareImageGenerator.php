<?php

namespace App\Domain\Creative\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareImageGenerator
{
    public function generate(string $prompt, int $userId, int $applicationId): array
    {
        $accountId = trim((string) config('creative.cloudflare.account_id'));
        $token = trim((string) config('creative.cloudflare.api_token'));
        $model = trim((string) config('creative.cloudflare.model', '@cf/black-forest-labs/flux-1-schnell'));

        if ($accountId === '' || $token === '') {
            throw new RuntimeException('O gerador de imagem por IA ainda não foi configurado no servidor.');
        }

        $this->consumeQuota($userId, $applicationId);

        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
            rawurlencode($accountId),
            $model
        );

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('creative.cloudflare.timeout', 45))
                ->retry(1, 350, throw: false)
                ->post($url, [
                    'prompt' => mb_substr($prompt, 0, 2048),
                    'steps' => (int) config('creative.cloudflare.steps', 4),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('O serviço de criação por IA está temporariamente indisponível.', 0, $exception);
        }

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'errors.0.message', 'Falha ao gerar a imagem pela IA.');
            throw new RuntimeException($message);
        }

        $payload = $response->json();
        $image = data_get($payload, 'result.image') ?? data_get($payload, 'image');

        if (! is_string($image) || trim($image) === '') {
            throw new RuntimeException('A IA respondeu sem uma imagem utilizável.');
        }

        return [
            'image' => $image,
            'mime_type' => 'image/jpeg',
            'model' => $model,
            'provider' => 'cloudflare_workers_ai',
        ];
    }

    private function consumeQuota(int $userId, int $applicationId): void
    {
        $ttl = now('UTC')->endOfDay()->addSecond();
        $globalKey = 'creative:cloudflare:requests:'.now('UTC')->format('Y-m-d');
        $userKey = sprintf('creative:cloudflare:user:%d:app:%d:%s', $userId, $applicationId, now('UTC')->format('Y-m-d'));
        $globalLimit = (int) config('creative.cloudflare.daily_request_budget', 200);
        $userLimit = (int) config('creative.cloudflare.per_user_daily_limit', 12);

        Cache::add($globalKey, 0, $ttl);
        Cache::add($userKey, 0, $ttl);

        if ((int) Cache::get($globalKey, 0) >= $globalLimit) {
            throw new RuntimeException('A franquia gratuita de geração por IA reservada para hoje chegou ao limite.');
        }

        if ((int) Cache::get($userKey, 0) >= $userLimit) {
            throw new RuntimeException('Você atingiu o limite gratuito de imagens por IA de hoje.');
        }

        Cache::increment($globalKey);
        Cache::increment($userKey);
    }
}
