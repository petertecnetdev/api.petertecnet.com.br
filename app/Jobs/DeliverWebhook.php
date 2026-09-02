<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->findOrFail($this->deliveryId);
        $endpoint = $delivery->endpoint;
        if (! $endpoint || ! $endpoint->is_active) return;

        $body = json_encode([
            'id' => $delivery->public_id,
            'event' => $delivery->event,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, (string) $endpoint->secret);
        $delivery->increment('attempts');

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Peter-Platform-Webhooks/1.0',
                    'X-Peter-Event' => $delivery->event,
                    'X-Peter-Delivery' => $delivery->public_id,
                    'X-Peter-Timestamp' => $timestamp,
                    'X-Peter-Signature' => 'sha256=' . $signature,
                ])
                ->timeout(15)
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, 5000),
                'status' => $response->successful() ? 'delivered' : 'failed',
                'delivered_at' => $response->successful() ? now() : null,
            ]);

            if ($response->successful()) {
                $endpoint->update(['failure_count' => 0, 'last_success_at' => now()]);
                return;
            }

            $endpoint->increment('failure_count');
            throw new \RuntimeException('Webhook endpoint returned HTTP ' . $response->status());
        } catch (Throwable $e) {
            $delivery->update([
                'status' => $this->attempts() >= $this->tries ? 'dead' : 'retrying',
                'next_attempt_at' => $this->attempts() >= $this->tries ? null : now()->addSeconds($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]),
            ]);
            throw $e;
        }
    }
}
