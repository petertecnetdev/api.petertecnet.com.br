<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;
    private const IDEMPOTENCY_TTL_SECONDS = 604800;

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            Log::critical('Stripe webhook rejected: signing secret is not configured.');
            return response()->json(['message' => 'Webhook unavailable.'], 503);
        }

        $payload = $request->getContent();
        $signatureHeader = (string) $request->header('Stripe-Signature', '');

        if (!$this->hasValidSignature($payload, $signatureHeader, $secret)) {
            Log::warning('Stripe webhook rejected: invalid signature.', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['id'], $event['type'])) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $eventId = (string) $event['id'];
        $eventType = (string) $event['type'];
        $cacheKey = 'stripe:webhook:event:'.hash('sha256', $eventId);

        if (!Cache::add($cacheKey, true, self::IDEMPOTENCY_TTL_SECONDS)) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            $this->process($event);
        } catch (\Throwable $exception) {
            Cache::forget($cacheKey);
            Log::error('Stripe webhook processing failed.', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['received' => true]);
    }

    private function hasValidSignature(string $payload, string $header, string $secret): bool
    {
        if ($payload === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && is_numeric($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && is_string($value) && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function process(array $event): void
    {
        $object = $event['data']['object'] ?? [];

        Log::info('Stripe webhook received.', [
            'event_id' => $event['id'],
            'event_type' => $event['type'],
            'stripe_account' => $event['account'] ?? null,
            'object_id' => is_array($object) ? ($object['id'] ?? null) : null,
            'livemode' => (bool) ($event['livemode'] ?? false),
        ]);

        // Provider-specific state transitions are intentionally delegated to
        // finance services. This endpoint first establishes a secure,
        // idempotent ingress for platform and Connect events.
        switch ((string) $event['type']) {
            case 'payment_intent.succeeded':
            case 'payment_intent.payment_failed':
            case 'charge.refunded':
            case 'charge.dispute.created':
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.async_payment_failed':
            case 'checkout.session.expired':
            case 'transfer.created':
            case 'transfer.reversed':
            case 'transfer.updated':
                break;
            default:
                // Unknown/new event types are acknowledged after signature
                // verification so Stripe does not retry indefinitely.
                break;
        }
    }
}
