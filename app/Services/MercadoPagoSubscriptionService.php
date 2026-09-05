<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoSubscriptionService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private function client(): PendingRequest
    {
        $token = (string) config('services.mercadopago.access_token');

        if ($token === '') {
            throw new RuntimeException('Mercado Pago não configurado: MERCADOPAGO_ACCESS_TOKEN ausente.');
        }

        return Http::baseUrl(self::BASE_URL)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250);
    }

    public function createSubscription(User $user, Subscription $subscription, array $plan, array $application, ?string $backUrl = null): array
    {
        $autoRecurring = [
            'frequency' => (int) $plan['frequency'],
            'frequency_type' => (string) $plan['frequency_type'],
            'transaction_amount' => (float) $plan['amount'],
            'currency_id' => (string) config('subscriptions.currency', 'BRL'),
        ];

        if ((int) ($plan['trial_days'] ?? 0) >= 30) {
            $autoRecurring['free_trial'] = [
                'frequency' => 1,
                'frequency_type' => 'months',
            ];
        }

        $response = $this->client()->post('/preapproval', [
            'reason' => sprintf('Peter Tecnet - %s - %s', $application['name'], $plan['name']),
            'external_reference' => (string) $subscription->external_reference,
            'payer_email' => (string) $user->email,
            'auto_recurring' => $autoRecurring,
            'back_url' => $backUrl ?: (string) config('subscriptions.checkout_back_url'),
        ]);

        $response->throw();

        return $response->json();
    }

    public function getSubscription(string $providerId): array
    {
        $response = $this->client()->get('/preapproval/' . rawurlencode($providerId));
        $response->throw();

        return $response->json();
    }

    public function updateSubscriptionStatus(string $providerId, string $status): array
    {
        $response = $this->client()->put('/preapproval/' . rawurlencode($providerId), [
            'status' => $status,
        ]);
        $response->throw();

        return $response->json();
    }

    public function getPayment(string $paymentId): array
    {
        $response = $this->client()->get('/v1/payments/' . rawurlencode($paymentId));
        $response->throw();

        return $response->json();
    }

    public function getAuthorizedPayment(string $invoiceId): array
    {
        $response = $this->client()->get('/authorized_payments/' . rawurlencode($invoiceId));
        $response->throw();

        return $response->json();
    }

    public function validateWebhook(Request $request): bool
    {
        $secret = (string) config('services.mercadopago.webhook_secret');
        $xSignature = (string) $request->header('x-signature', '');
        $xRequestId = (string) $request->header('x-request-id', '');
        $dataId = (string) $request->query('data.id', '');

        if ($secret === '' || $xSignature === '' || $xRequestId === '' || $dataId === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $providedHash = $parts['v1'] ?? null;
        if (! $timestamp || ! $providedHash) {
            return false;
        }

        $normalizedDataId = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', $normalizedDataId, $xRequestId, $timestamp);
        $expectedHash = hash_hmac('sha256', $manifest, $secret);

        if (! hash_equals($expectedHash, $providedHash)) {
            return false;
        }

        $numericTs = (int) $timestamp;
        if ($numericTs > 999999999999) {
            $numericTs = (int) floor($numericTs / 1000);
        }

        return abs(now()->timestamp - $numericTs) <= 900;
    }
}
