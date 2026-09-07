<?php

namespace App\Domain\Creative\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareTextGenerator
{
    public function generate(string $prompt, int $userId, int $applicationId): array
    {
        $accountId = trim((string) config('creative.cloudflare.account_id'));
        $token = trim((string) config('creative.cloudflare.api_token'));
        $model = trim((string) config('creative.cloudflare.text_model', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'));

        if ($accountId === '' || $token === '') {
            throw new RuntimeException('O gerador de texto por IA ainda não foi configurado no servidor.');
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
                ->timeout((int) config('creative.cloudflare.text_timeout', 30))
                ->retry(1, 300, throw: false)
                ->post($url, [
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Follow the supplied editorial instructions exactly. Return only the requested final text, without analysis or explanations.',
                        ],
                        [
                            'role' => 'user',
                            'content' => mb_substr($prompt, 0, 6000),
                        ],
                    ],
                    'max_tokens' => (int) config('creative.cloudflare.text_max_tokens', 700),
                    'temperature' => (float) config('creative.cloudflare.text_temperature', 0.65),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('O serviço de criação de texto por IA está temporariamente indisponível.', 0, $exception);
        }

        if (! $response->successful()) {
            $message = (string) data_get($response->json(), 'errors.0.message', 'Falha ao gerar o texto pela IA.');
            throw new RuntimeException($message);
        }

        $payload = $response->json();
        $text = data_get($payload, 'result.response')
            ?? data_get($payload, 'response')
            ?? data_get($payload, 'result.choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('A IA respondeu sem um texto utilizável.');
        }

        return [
            'text' => trim($text),
            'model' => $model,
            'provider' => 'cloudflare_workers_ai',
        ];
    }

    private function consumeQuota(int $userId, int $applicationId): void
    {
        $ttl = now('UTC')->endOfDay()->addSecond();
        $date = now('UTC')->format('Y-m-d');
        $globalKey = 'creative:cloudflare:text:requests:'.$date;
        $userKey = sprintf('creative:cloudflare:text:user:%d:app:%d:%s', $userId, $applicationId, $date);
        $globalLimit = (int) config('creative.cloudflare.text_daily_request_budget', 2000);
        $userLimit = (int) config('creative.cloudflare.text_per_user_daily_limit', 50);

        Cache::add($globalKey, 0, $ttl);
        Cache::add($userKey, 0, $ttl);

        if ((int) Cache::get($globalKey, 0) >= $globalLimit) {
            throw new RuntimeException('A franquia diária de geração de texto por IA chegou ao limite.');
        }

        if ((int) Cache::get($userKey, 0) >= $userLimit) {
            throw new RuntimeException('Você atingiu o limite diário de textos gerados por IA.');
        }

        Cache::increment($globalKey);
        Cache::increment($userKey);
    }
}
