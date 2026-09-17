<?php

namespace App\Integrations\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ExternalApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $maxRetries = 2,
        private readonly int $retryDelayMilliseconds = 200,
    ) {
        if ($this->timeoutSeconds < 1 || $this->connectTimeoutSeconds < 1) {
            throw new RuntimeException('HTTP timeouts must be positive.');
        }

        if ($this->maxRetries < 0 || $this->maxRetries > 5) {
            throw new RuntimeException('HTTP retries must be between 0 and 5.');
        }
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->send('get', $path, $query, $headers);
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload = [], array $headers = []): array
    {
        return $this->send('post', $path, $payload, $headers);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, array $data, array $headers): array
    {
        $request = $this->request($headers);
        $response = $method === 'get'
            ? $request->get($this->url($path), $data)
            : $request->post($this->url($path), $data);

        if ($response->failed()) {
            throw new ExternalApiException(
                provider: $this->baseUrl,
                status: $response->status(),
                message: 'External provider returned an unsuccessful response.',
            );
        }

        $json = $response->json();
        return is_array($json) ? $json : ['data' => $response->body()];
    }

    private function request(array $headers): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders($headers)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->retry(
                times: $this->maxRetries,
                sleepMilliseconds: $this->retryDelayMilliseconds,
                when: static function ($exception, $request): bool {
                    return $exception instanceof ConnectionException
                        || ($request->response?->serverError() ?? false)
                        || ($request->response?->status() === 429);
                },
            );
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}

final class ExternalApiException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}
