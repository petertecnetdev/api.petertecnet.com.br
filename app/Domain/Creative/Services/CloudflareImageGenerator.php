<?php

namespace App\Domain\Creative\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareImageGenerator
{
    public function generate(string $prompt, int $userId, int $applicationId, array $options = []): array
    {
        $accountId = trim((string) config('creative.cloudflare.account_id'));
        $token = trim((string) config('creative.cloudflare.api_token'));
        $requestedModel = trim((string) ($options['model'] ?? ''));
        $qualityModel = trim((string) config('creative.cloudflare.quality_model'));
        $model = $requestedModel !== ''
            ? $requestedModel
            : ($qualityModel !== ''
                ? $qualityModel
                : trim((string) config('creative.cloudflare.model', '@cf/black-forest-labs/flux-1-schnell')));

        if ($accountId === '' || $token === '') {
            throw new RuntimeException('O gerador de imagem por IA ainda não foi configurado no servidor.');
        }

        $this->consumeQuota($userId, $applicationId);

        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/%s',
            rawurlencode($accountId),
            $model
        );

        $width = $this->normalizeDimension($options['width'] ?? 1024);
        $height = $this->normalizeDimension($options['height'] ?? 1024);
        $steps = $this->normalizeSteps($options['steps'] ?? null, $model);

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('creative.cloudflare.timeout', 45))
                ->retry(1, 350, throw: false);

            $response = $this->usesMultipartPayload($model)
                ? $request->asMultipart()->post($url, $this->multipartPayload($model, $prompt, $width, $height, $steps))
                : $request->asJson()->post($url, $this->jsonPayload($model, $prompt, $width, $height, $steps));
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
            'requested_width' => $width,
            'requested_height' => $height,
            'requested_steps' => $steps,
        ];
    }

    private function jsonPayload(string $model, string $prompt, int $width, int $height, int $steps): array
    {
        $prompt = mb_substr($prompt, 0, 2048);

        if (str_contains($model, 'lucid-origin')) {
            return [
                'prompt' => $prompt,
                'width' => $width,
                'height' => $height,
                'guidance' => (float) config('creative.cloudflare.guidance', 4.5),
                'num_steps' => $steps,
            ];
        }

        return [
            'prompt' => $prompt,
            'steps' => $steps,
        ];
    }

    private function multipartPayload(string $model, string $prompt, int $width, int $height, int $steps): array
    {
        $parts = [
            ['name' => 'prompt', 'contents' => mb_substr($prompt, 0, 2048)],
            ['name' => 'width', 'contents' => (string) $width],
            ['name' => 'height', 'contents' => (string) $height],
            ['name' => 'guidance', 'contents' => (string) config('creative.cloudflare.guidance', 4.5)],
        ];

        if (str_contains($model, 'flux-2-dev')) {
            $parts[] = [
                'name' => 'steps',
                'contents' => (string) $steps,
            ];
        }

        return $parts;
    }

    private function usesMultipartPayload(string $model): bool
    {
        return str_contains($model, 'flux-2-');
    }

    private function normalizeDimension(mixed $value): int
    {
        return min(1920, max(256, (int) $value));
    }

    private function normalizeSteps(mixed $value, string $model): int
    {
        if ($value !== null && $value !== '') {
            return min(40, max(1, (int) $value));
        }

        return str_contains($model, 'flux-1-schnell')
            ? (int) config('creative.cloudflare.steps', 4)
            : (int) config('creative.cloudflare.quality_steps', 12);
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
            throw new RuntimeException('A franquia de geração por IA reservada para hoje chegou ao limite.');
        }

        if ((int) Cache::get($userKey, 0) >= $userLimit) {
            throw new RuntimeException('Você atingiu o limite de imagens por IA de hoje.');
        }

        Cache::increment($globalKey);
        Cache::increment($userKey);
    }
}
