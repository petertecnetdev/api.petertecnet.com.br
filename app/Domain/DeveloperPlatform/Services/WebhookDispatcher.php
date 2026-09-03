<?php

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Jobs\DeliverDeveloperWebhook;
use App\Domain\DeveloperPlatform\Models\WebhookDelivery;
use App\Domain\DeveloperPlatform\Models\WebhookEndpoint;
use Illuminate\Support\Str;

class WebhookDispatcher
{
    public function dispatch(string $event, array $data, int $apiClientId): int
    {
        if (!in_array($event, (array) config('developer.webhook_events', []), true)) {
            throw new \InvalidArgumentException("Evento de webhook não registrado: {$event}");
        }

        $eventId = (string) Str::uuid();
        $count = 0;

        WebhookEndpoint::query()
            ->where('api_client_id', $apiClientId)
            ->where('status', 'active')
            ->whereJsonContains('events', $event)
            ->each(function (WebhookEndpoint $endpoint) use ($event, $data, $eventId, &$count) {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $eventId,
                    'event' => $event,
                    'payload' => [
                        'id' => $eventId,
                        'type' => $event,
                        'created_at' => now()->toISOString(),
                        'data' => $data,
                    ],
                    'status' => 'pending',
                    'attempts' => 0,
                ]);

                DeliverDeveloperWebhook::dispatch($delivery->id);
                $count++;
            });

        return $count;
    }
}
