<?php

namespace App\Domain\DeveloperPlatform\Jobs;

use App\Domain\DeveloperPlatform\Models\WebhookDelivery;
use App\Domain\DeveloperPlatform\Services\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverDeveloperWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $deliveryId)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(WebhookUrlGuard $guard): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($this->deliveryId);
        $endpoint = $delivery->endpoint;

        if (!$endpoint || $endpoint->status !== 'active') {
            $delivery->update(['status' => 'cancelled']);
            return;
        }

        $guard->assertSafe($endpoint->url);

        $timestamp = (string) now()->timestamp;
        $rawPayload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $endpoint->secret);

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'Peter-Tecnet-Webhooks/1.0',
                'X-Peter-Event' => $delivery->event,
                'X-Peter-Event-Id' => $delivery->event_id,
                'X-Peter-Timestamp' => $timestamp,
                'X-Peter-Signature' => 'v1=' . $signature,
            ])
            ->withBody($rawPayload, 'application/json')
            ->post($endpoint->url);

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'http_status' => $response->status(),
        ])->save();

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'next_attempt_at' => null,
                'error' => null,
            ])->save();
            $endpoint->forceFill(['last_success_at' => now()])->save();
            return;
        }

        $delivery->forceFill([
            'status' => 'retrying',
            'next_attempt_at' => now()->addSeconds($this->backoff()[min($this->attempts() - 1, 2)]),
            'error' => 'HTTP ' . $response->status(),
        ])->save();
        $endpoint->forceFill(['last_failure_at' => now()])->save();

        throw new RuntimeException('Webhook delivery returned HTTP ' . $response->status());
    }

    public function failed(Throwable $exception): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => 'failed',
            'error' => mb_substr($exception->getMessage(), 0, 1000),
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }
}
