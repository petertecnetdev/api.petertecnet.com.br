<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\MercadoPagoSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SubscriptionController extends Controller
{
    public function __construct(private readonly MercadoPagoSubscriptionService $mercadoPago)
    {
    }

    public function catalog(): JsonResponse
    {
        $plans = collect(config('subscriptions.plans', []))->map(function (array $plan, string $key) {
            return [
                'key' => $key,
                'name' => $plan['name'],
                'amount' => (float) $plan['amount'],
                'frequency' => (int) $plan['frequency'],
                'frequency_type' => $plan['frequency_type'],
                'trial_days' => (int) ($plan['trial_days'] ?? 0),
            ];
        })->values();

        $applications = collect(config('subscriptions.applications', []))->map(function (array $app, string $key) {
            return [
                'key' => $key,
                'name' => $app['name'],
                'billing' => $app['billing'],
                'access' => $app['access'],
                'subscription_enabled' => $app['billing'] === 'subscription',
                'revenue_source' => $app['revenue_source'] ?? null,
            ];
        })->values();

        return response()->json([
            'provider' => config('subscriptions.provider'),
            'currency' => config('subscriptions.currency', 'BRL'),
            'plans' => $plans,
            'applications' => $applications,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $query = Subscription::query()
            ->where('user_id', $request->user()->id)
            ->latest();

        if ($request->filled('app')) {
            $query->where('application_key', (string) $request->query('app'));
        }

        return response()->json([
            'subscriptions' => $query->with('payments')->get(),
        ]);
    }

    public function access(Request $request, string $applicationKey): JsonResponse
    {
        $application = config('subscriptions.applications.' . $applicationKey);

        if (! is_array($application)) {
            return response()->json(['message' => 'Aplicativo de assinatura não encontrado.'], 404);
        }

        if (($application['billing'] ?? null) !== 'subscription') {
            return response()->json([
                'application' => $applicationKey,
                'has_access' => true,
                'billing' => $application['billing'],
                'access_mode' => $application['access'],
                'subscription' => null,
            ]);
        }

        $subscription = Subscription::query()
            ->where('user_id', $request->user()->id)
            ->where('application_key', $applicationKey)
            ->latest()
            ->get()
            ->first(fn (Subscription $item) => $item->hasAccess());

        $required = ($application['access'] ?? 'required') === 'required';

        return response()->json([
            'application' => $applicationKey,
            'has_access' => $required ? (bool) $subscription : true,
            'billing' => 'subscription',
            'access_mode' => $application['access'],
            'subscription' => $subscription,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application' => ['required', 'string', 'max:80'],
            'plan' => ['required', 'string', 'in:monthly,semiannual,annual'],
            'return_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $applicationKey = $validated['application'];
        $planKey = $validated['plan'];
        $application = config('subscriptions.applications.' . $applicationKey);
        $plan = config('subscriptions.plans.' . $planKey);
        $returnUrl = $validated['return_url'] ?? null;

        if ($returnUrl && ! $this->isSafePeterUrl($returnUrl)) {
            return response()->json(['message' => 'URL de retorno não permitida.'], 422);
        }

        if (! is_array($application) || ($application['billing'] ?? null) !== 'subscription') {
            return response()->json([
                'message' => 'Este aplicativo não utiliza assinatura como modelo de cobrança.',
            ], 422);
        }

        if (! is_array($plan)) {
            return response()->json(['message' => 'Plano inválido.'], 422);
        }

        $user = $request->user();

        $active = Subscription::query()
            ->where('user_id', $user->id)
            ->where('application_key', $applicationKey)
            ->latest()
            ->get()
            ->first(fn (Subscription $item) => $item->hasAccess());

        if ($active) {
            return response()->json([
                'message' => 'Você já possui acesso ativo a este aplicativo.',
                'subscription' => $active,
            ], 409);
        }

        $pending = Subscription::query()
            ->where('user_id', $user->id)
            ->where('application_key', $applicationKey)
            ->where('plan_key', $planKey)
            ->where('status', 'pending')
            ->whereNotNull('checkout_url')
            ->where('created_at', '>=', now()->subHours(6))
            ->latest()
            ->first();

        if ($pending) {
            return response()->json([
                'subscription' => $pending,
                'checkout_url' => $pending->checkout_url,
                'reused' => true,
            ]);
        }

        $subscription = DB::transaction(function () use ($user, $applicationKey, $planKey, $plan) {
            return Subscription::create([
                'user_id' => $user->id,
                'application_key' => $applicationKey,
                'provider' => config('subscriptions.provider', 'mercadopago'),
                'plan_key' => $planKey,
                'status' => 'pending',
                'amount' => $plan['amount'],
                'currency' => config('subscriptions.currency', 'BRL'),
                'external_reference' => (string) Str::uuid(),
                'trial_ends_at' => ((int) ($plan['trial_days'] ?? 0) >= 30) ? now()->addMonth() : null,
                'metadata' => [
                    'application_name' => $application['name'],
                    'plan_name' => $plan['name'],
                ],
            ]);
        });

        try {
            $provider = $this->mercadoPago->createSubscription($user, $subscription, $plan, $application, $returnUrl);

            $subscription->update([
                'provider_subscription_id' => $provider['id'] ?? null,
                'provider_payment_method' => $provider['payment_method_id'] ?? null,
                'checkout_url' => $provider['init_point'] ?? null,
                'status' => $provider['status'] ?? 'pending',
                'next_payment_at' => $provider['next_payment_date'] ?? null,
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'provider_version' => $provider['version'] ?? null,
                    'return_url' => $returnUrl,
                ]),
            ]);
        } catch (Throwable $e) {
            $subscription->update([
                'status' => 'failed',
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'provider_error' => $e->getMessage(),
                ]),
            ]);

            report($e);

            return response()->json([
                'message' => 'Não foi possível iniciar a assinatura no Mercado Pago.',
            ], 502);
        }

        return response()->json([
            'subscription' => $subscription->fresh(),
            'checkout_url' => $subscription->checkout_url,
        ], 201);
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeOwner($request, $subscription);

        if ($subscription->provider_subscription_id) {
            $provider = $this->mercadoPago->updateSubscriptionStatus($subscription->provider_subscription_id, 'cancelled');
            $subscription->update([
                'status' => $provider['status'] ?? 'cancelled',
                'cancelled_at' => now(),
            ]);
        } else {
            $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }

        return response()->json(['subscription' => $subscription->fresh()]);
    }

    public function pause(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeOwner($request, $subscription);

        if (! $subscription->provider_subscription_id) {
            return response()->json(['message' => 'Assinatura ainda não vinculada ao Mercado Pago.'], 422);
        }

        $provider = $this->mercadoPago->updateSubscriptionStatus($subscription->provider_subscription_id, 'paused');
        $subscription->update(['status' => $provider['status'] ?? 'paused']);

        return response()->json(['subscription' => $subscription->fresh()]);
    }

    public function resume(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeOwner($request, $subscription);

        if (! $subscription->provider_subscription_id) {
            return response()->json(['message' => 'Assinatura ainda não vinculada ao Mercado Pago.'], 422);
        }

        $provider = $this->mercadoPago->updateSubscriptionStatus($subscription->provider_subscription_id, 'authorized');
        $this->syncProviderSubscription($subscription, $provider);

        return response()->json(['subscription' => $subscription->fresh()]);
    }

    public function webhook(Request $request): JsonResponse
    {
        if (! $this->mercadoPago->validateWebhook($request)) {
            return response()->json(['message' => 'Assinatura de webhook inválida.'], 401);
        }

        $type = (string) ($request->input('type') ?: $request->input('topic'));
        $resourceId = (string) ($request->query('data.id') ?: data_get($request->all(), 'data.id', ''));

        if ($resourceId === '') {
            return response()->json(['received' => true]);
        }

        try {
            if ($type === 'subscription_preapproval') {
                $provider = $this->mercadoPago->getSubscription($resourceId);
                $subscription = $this->findSubscriptionFromProvider($provider, $resourceId);
                if ($subscription) {
                    $this->syncProviderSubscription($subscription, $provider);
                }
            } elseif ($type === 'payment') {
                $payment = $this->mercadoPago->getPayment($resourceId);
                $this->syncPayment($payment);
            } elseif ($type === 'subscription_authorized_payment') {
                $invoice = $this->mercadoPago->getAuthorizedPayment($resourceId);
                $this->syncAuthorizedPayment($invoice, $resourceId);
            }
        } catch (Throwable $e) {
            report($e);
            return response()->json(['received' => true, 'processed' => false]);
        }

        return response()->json(['received' => true, 'processed' => true]);
    }

    private function syncProviderSubscription(Subscription $subscription, array $provider): void
    {
        $status = (string) ($provider['status'] ?? $subscription->status);

        $subscription->update([
            'status' => $status,
            'provider_payment_method' => $provider['payment_method_id'] ?? $subscription->provider_payment_method,
            'checkout_url' => $provider['init_point'] ?? $subscription->checkout_url,
            'next_payment_at' => $provider['next_payment_date'] ?? $subscription->next_payment_at,
            'cancelled_at' => $status === 'cancelled' ? ($subscription->cancelled_at ?? now()) : $subscription->cancelled_at,
        ]);
    }

    private function syncPayment(array $payment): void
    {
        $externalReference = (string) ($payment['external_reference'] ?? '');
        if ($externalReference === '') {
            return;
        }

        $subscription = Subscription::where('external_reference', $externalReference)->first();
        if (! $subscription) {
            return;
        }

        SubscriptionPayment::updateOrCreate(
            ['provider_payment_id' => (string) ($payment['id'] ?? '')],
            [
                'subscription_id' => $subscription->id,
                'status' => (string) ($payment['status'] ?? 'unknown'),
                'amount' => $payment['transaction_amount'] ?? null,
                'currency' => (string) ($payment['currency_id'] ?? $subscription->currency),
                'paid_at' => ($payment['status'] ?? null) === 'approved' ? ($payment['date_approved'] ?? now()) : null,
                'raw_payload' => $payment,
            ]
        );

        if (($payment['status'] ?? null) === 'approved') {
            $months = (int) config('subscriptions.plans.' . $subscription->plan_key . '.frequency', 1);
            $subscription->update([
                'status' => 'authorized',
                'current_period_ends_at' => now()->addMonthsNoOverflow($months),
            ]);
        } elseif (in_array(($payment['status'] ?? null), ['rejected', 'cancelled'], true)) {
            $subscription->update(['status' => 'past_due']);
        }
    }

    private function syncAuthorizedPayment(array $invoice, string $invoiceId): void
    {
        $providerSubscriptionId = (string) ($invoice['preapproval_id'] ?? '');
        if ($providerSubscriptionId === '') {
            return;
        }

        $subscription = Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();
        if (! $subscription) {
            return;
        }

        $payment = (array) ($invoice['payment'] ?? []);
        $paymentId = isset($payment['id']) ? (string) $payment['id'] : null;
        $status = (string) ($invoice['status'] ?? ($payment['status'] ?? 'unknown'));

        SubscriptionPayment::updateOrCreate(
            $paymentId ? ['provider_payment_id' => $paymentId] : ['provider_invoice_id' => $invoiceId],
            [
                'subscription_id' => $subscription->id,
                'provider_invoice_id' => $invoiceId,
                'status' => $status,
                'amount' => $invoice['transaction_amount'] ?? ($payment['transaction_amount'] ?? null),
                'currency' => (string) ($invoice['currency_id'] ?? $subscription->currency),
                'paid_at' => in_array($status, ['approved', 'processed'], true) ? now() : null,
                'raw_payload' => $invoice,
            ]
        );
    }

    private function findSubscriptionFromProvider(array $provider, string $providerId): ?Subscription
    {
        $externalReference = (string) ($provider['external_reference'] ?? '');

        return Subscription::query()
            ->where('provider_subscription_id', $providerId)
            ->when($externalReference !== '', fn ($query) => $query->orWhere('external_reference', $externalReference))
            ->first();
    }

    private function isSafePeterUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https'
            && ($host === 'petertecnet.com.br' || str_ends_with($host, '.petertecnet.com.br'));
    }

    private function authorizeOwner(Request $request, Subscription $subscription): void
    {
        abort_unless((int) $subscription->user_id === (int) $request->user()->id, 403);
    }
}
