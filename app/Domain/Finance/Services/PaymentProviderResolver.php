<?php

namespace App\Domain\Finance\Services;

final class PaymentProviderResolver
{
    public function resolve(string $applicationSlug, ?string $requested = null): string
    {
        if ($requested !== null && trim($requested) !== '') {
            return trim($requested);
        }

        return (string) config(
            'platform.applications.' . $applicationSlug . '.payments.provider',
            config('platform.payments.default_provider', env('COMMERCE_PAYMENT_PROVIDER', 'mercadopago'))
        );
    }
}
