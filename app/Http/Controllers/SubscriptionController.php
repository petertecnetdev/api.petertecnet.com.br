<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function catalog(): JsonResponse
    {
        return response()->json($this->subscriptions->catalog());
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->subscriptions->listFor(
            $request->user(),
            $request->filled('app') ? (string) $request->query('app') : null,
        ));
    }

    public function access(Request $request, string $applicationKey): JsonResponse
    {
        return $this->respond($this->subscriptions->accessFor($request->user(), $applicationKey));
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application' => ['required', 'string', 'max:80'],
            'plan' => ['required', 'string', 'in:monthly,semiannual,annual'],
            'return_url' => ['nullable', 'url', 'max:2048'],
        ]);

        return $this->respond($this->subscriptions->checkout(
            $request->user(),
            (string) $validated['application'],
            (string) $validated['plan'],
            $validated['return_url'] ?? null,
        ));
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->respond($this->subscriptions->cancel($request->user(), $subscription));
    }

    public function pause(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->respond($this->subscriptions->pause($request->user(), $subscription));
    }

    public function resume(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->respond($this->subscriptions->resume($request->user(), $subscription));
    }

    public function webhook(Request $request): JsonResponse
    {
        return $this->respond($this->subscriptions->handleWebhook($request));
    }

    private function respond(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }
}
