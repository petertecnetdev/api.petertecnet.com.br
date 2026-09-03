<?php

namespace App\Services\Payments;

use App\Models\EcosystemPayment;
use Illuminate\Support\Facades\Schema;

class PaymentLifecycleService
{
    public function expireStaleOpenPayments(int $limit = 500): int
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return 0;
        }

        $fallbackMinutes = max(5, (int) config('commerce.payments.pending_expiration_minutes', 30));
        $payments = EcosystemPayment::query()
            ->whereIn('status', ['pending', 'in_process', 'authorized'])
            ->where(function ($query) use ($fallbackMinutes) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('expires_at')->where('expires_at', '<=', now());
                })->orWhere(function ($inner) use ($fallbackMinutes) {
                    $inner->whereNull('expires_at')
                        ->where('method', 'pix')
                        ->where('created_at', '<=', now()->subMinutes($fallbackMinutes));
                });
            })
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 2000)))
            ->get();

        foreach ($payments as $payment) {
            $payment->forceFill([
                'status' => 'expired',
                'failed_at' => $payment->failed_at ?: now(),
                'reconciliation_status' => $payment->reconciliation_status === 'matched' ? 'matched' : 'unverified',
                'reconciliation_message' => 'Cobrança expirada sem confirmação de pagamento.',
            ])->save();
        }

        return $payments->count();
    }
}
