<?php

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Models\WebhookDelivery;
use App\Domain\DeveloperPlatform\Models\WebhookEndpoint;
use App\Domain\DeveloperPlatform\Services\WebhookDispatcher;
use App\Domain\DeveloperPlatform\Services\WebhookUrlGuard;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DeveloperWebhookController extends Controller
{
    public function index(Request $request, ApiClient $client): JsonResponse
    {
        $this->assertOwned($request, $client);

        $webhooks = $client->webhooks()->latest()->get()->map(fn (WebhookEndpoint $endpoint) => $this->serialize($endpoint));
        return ApiResponse::data($webhooks);
    }

    public function store(Request $request, ApiClient $client, WebhookUrlGuard $guard): JsonResponse
    {
        $this->assertOwned($request, $client);
        $events = (array) config('developer.webhook_events', []);
        $validated = $request->validate([
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($events)],
        ]);

        try {
            $guard->assertSafe($validated['url']);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($request, 'unsafe_webhook_url', $exception->getMessage(), 422);
        }

        $secret = 'whsec_' . Str::random(48);
        $endpoint = $client->webhooks()->create([
            'url' => $validated['url'],
            'events' => array_values(array_unique($validated['events'])),
            'secret' => $secret,
            'status' => 'active',
        ]);

        return ApiResponse::data([
            'webhook' => $this->serialize($endpoint),
            'signing_secret' => $secret,
            'signing_secret_notice' => 'Copie agora. O segredo completo não será exibido novamente.',
        ], [], 201);
    }

    public function update(Request $request, ApiClient $client, WebhookEndpoint $webhook, WebhookUrlGuard $guard): JsonResponse
    {
        $this->assertEndpointOwned($request, $client, $webhook);
        $events = (array) config('developer.webhook_events', []);
        $validated = $request->validate([
            'url' => ['sometimes', 'url:https', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in($events)],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);

        if (isset($validated['url'])) {
            try {
                $guard->assertSafe($validated['url']);
            } catch (InvalidArgumentException $exception) {
                return ApiResponse::error($request, 'unsafe_webhook_url', $exception->getMessage(), 422);
            }
        }

        if (isset($validated['events'])) {
            $validated['events'] = array_values(array_unique($validated['events']));
        }

        $webhook->fill($validated)->save();
        return ApiResponse::data($this->serialize($webhook->fresh()));
    }

    public function destroy(Request $request, ApiClient $client, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertEndpointOwned($request, $client, $webhook);
        $webhook->forceFill(['status' => 'disabled'])->save();
        return ApiResponse::data(['disabled' => true]);
    }

    public function rotateSecret(Request $request, ApiClient $client, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertEndpointOwned($request, $client, $webhook);
        $secret = 'whsec_' . Str::random(48);
        $webhook->forceFill(['secret' => $secret])->save();

        return ApiResponse::data([
            'webhook' => $this->serialize($webhook),
            'signing_secret' => $secret,
            'signing_secret_notice' => 'Copie agora. O segredo completo não será exibido novamente.',
        ]);
    }

    public function test(Request $request, ApiClient $client, WebhookDispatcher $dispatcher): JsonResponse
    {
        $this->assertOwned($request, $client);
        $queued = $dispatcher->dispatch('developer.webhook.test', [
            'client_id' => $client->client_id,
            'message' => 'Peter Tecnet webhook test',
        ], $client->id);

        return ApiResponse::data(['queued_deliveries' => $queued], [], 202);
    }

    public function deliveries(Request $request, ApiClient $client, WebhookEndpoint $webhook): JsonResponse
    {
        $this->assertEndpointOwned($request, $client, $webhook);
        $deliveries = $webhook->deliveries()->latest()->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return ApiResponse::paginated($deliveries, fn (WebhookDelivery $delivery) => [
            'event_id' => $delivery->event_id,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'http_status' => $delivery->http_status,
            'attempts' => $delivery->attempts,
            'delivered_at' => optional($delivery->delivered_at)?->toISOString(),
            'created_at' => optional($delivery->created_at)?->toISOString(),
        ]);
    }

    public function events(): JsonResponse
    {
        return ApiResponse::data(config('developer.webhook_events', []));
    }

    private function assertOwned(Request $request, ApiClient $client): void
    {
        abort_unless($client->user_id === $request->user()->id, 404);
    }

    private function assertEndpointOwned(Request $request, ApiClient $client, WebhookEndpoint $webhook): void
    {
        $this->assertOwned($request, $client);
        abort_unless($webhook->api_client_id === $client->id, 404);
    }

    private function serialize(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events ?? [],
            'status' => $endpoint->status,
            'last_success_at' => optional($endpoint->last_success_at)?->toISOString(),
            'last_failure_at' => optional($endpoint->last_failure_at)?->toISOString(),
            'created_at' => optional($endpoint->created_at)?->toISOString(),
        ];
    }
}
