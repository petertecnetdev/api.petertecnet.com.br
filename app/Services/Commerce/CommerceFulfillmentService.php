<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderFulfillmentEvent;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommerceFulfillmentService
{
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const LEGACY_READY = 'available';
    public const FULFILLED = 'fulfilled';
    public const DELIVERED = 'delivered';
    public const BLOCKED = 'blocked';

    public function __construct(
        private readonly AppNotificationService $notifications,
    ) {}

    public function transition(Order $order, string $targetStatus, User $actor, array $context = []): Order
    {
        return DB::transaction(function () use ($order, $targetStatus, $actor, $context) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = $this->normalizeStatus($locked->fulfillment_status);

            abort_unless($locked->payment_status === 'paid', 422, 'O pagamento precisa estar confirmado antes do preparo.');
            abort_if($this->isTerminal($current), 409, 'O recebimento deste pedido já foi concluído.');
            abort_unless(in_array($targetStatus, [self::PREPARING, self::READY], true), 422, 'Status de recebimento inválido.');

            if ($current === $targetStatus) {
                return $locked->fresh(['items.item']);
            }

            $allowed = match ($current) {
                '', 'pending' => [self::PREPARING],
                self::PREPARING => [self::READY],
                self::READY => [self::PREPARING],
                self::LEGACY_READY => [self::PREPARING, self::READY],
                default => [],
            };

            abort_unless(in_array($targetStatus, $allowed, true), 422, 'Transição de recebimento não permitida.');

            $locked->forceFill([
                'fulfillment_status' => $targetStatus,
                'status' => $targetStatus,
                'status_updated_at' => now(),
            ])->save();

            $this->record(
                $locked,
                'status_changed',
                $actor->id,
                $current ?: null,
                $targetStatus,
                'seller_action',
                'success',
                $context
            );

            if ($targetStatus === self::READY && $locked->client_id) {
                DB::afterCommit(function () use ($locked) {
                    $this->notifications->sendToUser((int) $locked->app_id, (int) $locked->client_id, [
                        'type' => 'commerce.fulfillment.ready',
                        'title' => $locked->fulfillment === 'delivery' ? 'Pedido pronto para entrega' : 'Pedido pronto para retirada',
                        'message' => $locked->fulfillment === 'delivery'
                            ? 'Seu pedido #' . $locked->order_number . ' está pronto para ser entregue.'
                            : 'Seu pedido #' . $locked->order_number . ' está pronto para retirada.',
                        'reference_type' => 'order',
                        'reference_id' => (string) $locked->public_id,
                        'data' => [
                            'order_public_id' => $locked->public_id,
                            'order_number' => $locked->order_number,
                            'fulfillment' => $locked->fulfillment,
                            'fulfillment_status' => self::READY,
                        ],
                    ]);
                });
            }

            return $locked->fresh(['items.item']);
        }, 3);
    }

    public function verify(Order $order, ?string $token, ?string $code, ?User $actor = null, array $context = []): Order
    {
        $status = $this->normalizeStatus($order->fulfillment_status);

        if ($this->isTerminal($status)) {
            $this->record($order, 'credential_verified', $actor?->id, $status, $status, null, 'already_redeemed', $context);
            abort(409, 'Este comprovante já foi utilizado.');
        }

        abort_unless($order->payment_status === 'paid', 422, 'Pagamento ainda não confirmado.');
        abort_unless($this->isReady($status), 422, 'O pedido ainda não está pronto para retirada ou entrega.');

        $method = $this->credentialType($order, $token, $code);
        if (! $method) {
            $this->record($order, 'credential_verified', $actor?->id, $status, $status, null, 'invalid_credential', $context);
            abort(403, 'Comprovante ou código de retirada inválido.');
        }

        $this->record($order, 'credential_verified', $actor?->id, $status, $status, $method, 'success', $context);

        return $order->fresh(['items.item']);
    }

    public function redeem(Order $order, ?string $token, ?string $code, User $actor, array $context = []): Order
    {
        return DB::transaction(function () use ($order, $token, $code, $actor, $context) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $status = $this->normalizeStatus($locked->fulfillment_status);

            if ($this->isTerminal($status)) {
                $this->record($locked, 'redeem_attempted', $actor->id, $status, $status, null, 'already_redeemed', $context);
                abort(409, 'Este comprovante já foi utilizado.');
            }

            abort_unless($locked->payment_status === 'paid', 422, 'Pagamento ainda não confirmado.');
            abort_unless($this->isReady($status), 422, 'O pedido ainda não está pronto para retirada ou entrega.');

            $method = $this->credentialType($locked, $token, $code);
            if (! $method) {
                $this->record($locked, 'redeem_attempted', $actor->id, $status, $status, null, 'invalid_credential', $context);
                abort(403, 'Comprovante ou código de retirada inválido.');
            }

            $completedStatus = $locked->fulfillment === 'delivery' ? self::DELIVERED : self::FULFILLED;

            $locked->forceFill([
                'fulfillment_status' => $completedStatus,
                'fulfilled_at' => now(),
                'fulfilled_by' => $actor->id,
                'status' => 'completed',
                'status_updated_at' => now(),
                'attended_at' => now(),
            ])->save();

            $this->record(
                $locked,
                'redeemed',
                $actor->id,
                $status,
                $completedStatus,
                $method,
                'success',
                $context
            );

            return $locked->fresh(['items.item']);
        }, 3);
    }

    public function claimPayload(Order $order): ?array
    {
        if ($order->payment_status !== 'paid' || ! $this->isReady($order->fulfillment_status)) {
            return null;
        }

        return [
            'token' => $this->claimToken($order),
            'code' => $this->claimCode($order),
            'public_id' => $order->public_id,
            'single_use' => true,
        ];
    }

    public function claimToken(Order $order): string
    {
        return hash_hmac(
            'sha256',
            implode('|', ['fulfillment', $order->public_id, $order->id, $order->app_id, $order->client_id]),
            (string) config('app.key')
        );
    }

    public function claimCode(Order $order): string
    {
        $digest = hash_hmac(
            'sha256',
            implode('|', ['fulfillment-code', $order->public_id, $order->id, $order->app_id, $order->client_id]),
            (string) config('app.key')
        );
        $number = hexdec(substr($digest, 0, 12)) % 1000000;
        $digits = str_pad((string) $number, 6, '0', STR_PAD_LEFT);

        return 'RT-' . substr($digits, 0, 3) . '-' . substr($digits, 3, 3);
    }

    public function credentialType(Order $order, ?string $token, ?string $code): ?string
    {
        $token = trim((string) $token);
        if ($token !== '' && hash_equals($this->claimToken($order), $token)) {
            return 'qr_token';
        }

        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode !== '' && hash_equals($this->normalizeCode($this->claimCode($order)), $normalizedCode)) {
            return 'manual_code';
        }

        return null;
    }

    public function history(Order $order, int $limit = 30): array
    {
        return OrderFulfillmentEvent::query()
            ->where('order_id', $order->id)
            ->with('actor:id,first_name,last_name,user_name,email')
            ->latest('id')
            ->limit(min(max($limit, 1), 100))
            ->get()
            ->map(fn (OrderFulfillmentEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'validation_method' => $event->validation_method,
                'result' => $event->result,
                'actor' => $event->actor?->only(['id', 'first_name', 'last_name', 'user_name', 'email']),
                'metadata' => $event->metadata,
                'created_at' => optional($event->created_at)->toIso8601String(),
            ])->values()->all();
    }

    public function record(
        Order $order,
        string $event,
        ?int $actorUserId = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $validationMethod = null,
        string $result = 'success',
        array $context = []
    ): OrderFulfillmentEvent {
        return OrderFulfillmentEvent::query()->create([
            'order_id' => $order->id,
            'app_id' => $order->app_id,
            'establishment_id' => $order->entity_id,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'validation_method' => $validationMethod,
            'result' => $result,
            'request_id' => $context['request_id'] ?? null,
            'session_id_hash' => $this->privacyHash($context['session_id'] ?? null),
            'ip_hash' => $this->privacyHash($context['ip'] ?? null),
            'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }

    public function requestContext(Request $request, array $metadata = []): array
    {
        return [
            'request_id' => $request->header('X-Request-Id'),
            'session_id' => $request->header('X-Session-Id') ?: $request->header('X-Interaction-Session'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
        ];
    }

    public function isReady(?string $status): bool
    {
        return in_array($this->normalizeStatus($status), [self::READY, self::LEGACY_READY], true);
    }

    public function isTerminal(?string $status): bool
    {
        return in_array($this->normalizeStatus($status), [self::FULFILLED, self::DELIVERED, self::BLOCKED], true);
    }

    private function normalizeStatus(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    private function normalizeCode(?string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $code));
    }

    private function privacyHash(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
