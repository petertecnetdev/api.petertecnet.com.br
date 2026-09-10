<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\SubscriptionIntent;
use Illuminate\Support\Facades\DB;

final class SubscriptionPaymentLocator
{
    public function providerPaymentId(SubscriptionIntent $intent): ?string
    {
        $payment = DB::table('ecosystem_payments')
            ->where('app_slug', $intent->application)
            ->where('source_type', 'subscription_intent')
            ->where('source_reference', $intent->public_id)
            ->first();

        $providerPaymentId = trim((string) ($payment?->provider_payment_id ?? ''));

        return $providerPaymentId !== '' ? $providerPaymentId : null;
    }
}
