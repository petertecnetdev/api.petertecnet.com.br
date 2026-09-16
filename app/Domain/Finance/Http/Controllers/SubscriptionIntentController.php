<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Actions\FindRecoverableSubscriptionIntent;
use App\Domain\Finance\Models\SubscriptionIntent;
use App\Domain\Finance\Services\PlanCatalogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionIntentController extends Controller
{
    public function store(Request $request, string $application, PlanCatalogService $planCatalog): JsonResponse
    {
        $application = strtolower(trim($application));
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:120'],
            'handoff_channel' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array'],
        ]);

        $plan = $planCatalog->find($application, $validated['plan_code']);
        if (! $plan) {
            return response()->json(['message' => 'Plano de assinatura inválido ou indisponível.'], 422);
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            return response()->json(['message' => 'Envie um Idempotency-Key válido para criar a intenção.'], 422);
        }

        $user = $request->user();
        $identity = [
            'user_id' => $user->getKey(),
            'application' => $application,
            'idempotency_key' => $idempotencyKey,
        ];

        $existing = SubscriptionIntent::query()->where($identity)->first();
        if ($existing && $existing->plan_code !== $validated['plan_code']) {
            return response()->json(['message' => 'Este Idempotency-Key já foi utilizado para outro plano.'], 409);
        }

        $intent = $existing ?: SubscriptionIntent::firstOrCreate(
            $identity,
            [
                'public_id' => (string) Str::uuid(),
                'plan_code' => (string) $plan['code'],
                'plan_name' => (string) ($plan['name'] ?? $plan['code']),
                'price_cents' => max(0, (int) ($plan['price_cents'] ?? 0)),
                'currency' => (string) ($plan['currency'] ?? config('subscriptions.currency', 'BRL')),
                'billing_interval' => (string) ($plan['billing_interval'] ?? config('subscriptions.billing_interval', 'month')),
                'billing_interval_count' => (int) ($plan['billing_interval_count'] ?? config('subscriptions.billing_interval_count', 1)),
                'status' => 'created',
                'source' => $validated['source'] ?? null,
                'handoff_channel' => $validated['handoff_channel'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]
        );

        if (! $existing && ! $intent->wasRecentlyCreated && $intent->plan_code !== $validated['plan_code']) {
            return response()->json(['message' => 'Este Idempotency-Key já foi utilizado para outro plano.'], 409);
        }

        return response()->json(['data' => $this->resource($intent)], $existing || ! $intent->wasRecentlyCreated ? 200 : 201);
    }

    public function recoverable(Request $request, string $application, FindRecoverableSubscriptionIntent $findRecoverableSubscriptionIntent): JsonResponse
    {
        $application = strtolower(trim($application));
        $intent = $findRecoverableSubscriptionIntent->handle($request->user()->getKey(), $application);

        return response()->json(['data' => $intent ? $this->recoveryResource($intent) : null]);
    }

    public function show(Request $request, string $application, string $intent): JsonResponse
    {
        $record = SubscriptionIntent::query()
            ->where('public_id', $intent)
            ->where('application', strtolower(trim($application)))
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        return response()->json(['data' => $record]);
    }

    private function resource(SubscriptionIntent $intent): array
    {
        return [
            'id' => $intent->public_id,
            'application' => $intent->application,
            'plan_code' => $intent->plan_code,
            'plan_name' => $intent->plan_name,
            'price_cents' => $intent->price_cents,
            'currency' => $intent->currency,
            'billing_interval' => $intent->billing_interval,
            'billing_interval_count' => $intent->billing_interval_count,
            'status' => $intent->status,
            'source' => $intent->source,
            'handoff_channel' => $intent->handoff_channel,
            'created_at' => $intent->created_at,
        ];
    }

    private function recoveryResource(SubscriptionIntent $intent): array
    {
        return [
            ...$this->resource($intent),
            'metadata' => $intent->metadata,
            'checkout_started_at' => $intent->checkout_started_at,
            'payment_pending_at' => $intent->payment_pending_at,
        ];
    }
}
