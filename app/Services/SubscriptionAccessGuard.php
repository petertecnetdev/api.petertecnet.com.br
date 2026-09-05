<?php

namespace App\Services;

use App\Models\Subscription;
use App\Support\ApplicationResolver;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class SubscriptionAccessGuard
{
    public function __construct(private readonly ApplicationResolver $applications)
    {
    }

    public function enforce(Request $request): void
    {
        if ($this->isExemptRoute($request)) {
            return;
        }

        $user = $request->user();
        if (! $user) {
            return;
        }

        $application = $this->applications->source($request);
        if (! $application) {
            return;
        }

        $billing = config('subscriptions.applications.' . $application->slug);
        if (! is_array($billing)
            || ($billing['billing'] ?? null) !== 'subscription'
            || ($billing['access'] ?? 'required') !== 'required') {
            return;
        }

        $hasAccess = Subscription::query()
            ->where('user_id', $user->id)
            ->where('application_key', $application->slug)
            ->latest()
            ->get()
            ->contains(fn (Subscription $subscription) => $subscription->hasAccess());

        if ($hasAccess) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'É necessária uma assinatura ativa para continuar usando este aplicativo.',
            'code' => 'SUBSCRIPTION_REQUIRED',
            'application' => $application->slug,
            'checkout' => '/api/subscriptions/checkout',
        ], 402));
    }

    private function isExemptRoute(Request $request): bool
    {
        $name = (string) optional($request->route())->getName();

        if ($name === '') {
            return false;
        }

        foreach (['subscriptions.', 'account.', 'auth.', 'admin.'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
