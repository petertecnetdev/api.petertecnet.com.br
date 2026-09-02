<?php

namespace App\Application\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\ApiProject;
use App\Models\WebhookDelivery;

final class WebhookDispatcher
{
    public function dispatch(ApiProject $project, string $event, array $payload): void
    {
        $project->webhooks()
            ->where('is_active', true)
            ->get()
            ->filter(fn ($endpoint) => $endpoint->listensTo($event))
            ->each(function ($endpoint) use ($event, $payload) {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'status' => 'pending',
                    'payload' => $payload,
                ]);

                DeliverWebhook::dispatch($delivery->id);
            });
    }
}
