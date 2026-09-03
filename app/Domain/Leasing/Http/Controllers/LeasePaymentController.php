<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\RecipientSettlementService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class LeasePaymentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly RecipientSettlementService $settlement,
    ) {}

    public function checkout(Request $request, int $leaseId, int $chargeId)
    {
        $lease = $this->leaseForUser($request, $leaseId);
        $data = $request->validate(['method' => 'required|in:pix,boleto,card']);
        $charge = DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->where('id', $chargeId)
            ->firstOrFail();
        abort_if($charge->status === 'paid', 422, 'Esta cobrança já está paga.');

        $recipient = User::query()->findOrFail((int) $lease->landlord_user_id);
        $this->settlement->assertReadyForCollection($recipient);

        $payment = DB::table('ecosystem_payments')
            ->where('app_slug', $this->context->slug())
            ->where('source_type', 'lease_charge')
            ->where('source_reference', $charge->public_id)
            ->first();

        if (! $payment) {
            $id = DB::table('ecosystem_payments')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'app_id' => $this->context->id(),
                'app_slug' => $this->context->slug(),
                'provider' => 'mercadopago',
                'source_type' => 'lease_charge',
                'source_reference' => $charge->public_id,
                'source_id' => $charge->id,
                'user_id' => $lease->tenant_user_id ?: $request->user()->id,
                'currency' => 'BRL',
                'method' => $data['method'],
                'status' => 'pending',
                'gross_amount' => $charge->amount,
                'platform_fee' => 0,
                'provider_fee' => 0,
                'seller_net' => $charge->amount,
                'metadata' => json_encode([
                    'lease_id' => $leaseId,
                    'charge_id' => $chargeId,
                    'recipient_user_id' => $recipient->id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $payment = DB::table('ecosystem_payments')->find($id);
        }

        abort_if(in_array($payment->status, ['paid', 'refunded', 'charged_back'], true), 422, 'Esta cobrança não pode iniciar um novo checkout no estado atual.');
        $metadata = $payment->metadata ? json_decode($payment->metadata, true) : [];
        if (($metadata['requested_method'] ?? null) === $data['method'] && ! empty($metadata['checkout_url'])) {
            return response()->json([
                'payment' => $payment,
                'charge' => $charge,
                'checkout_url' => $metadata['checkout_url'],
                'preference_id' => $metadata['checkout_preference_id'] ?? null,
            ]);
        }

        $frontend = rtrim((string) ($this->context->application()->url ?: 'https://petertecnet.com.br'), '/');
        $webhook = rtrim((string) config('app.url'), '/').'/api/v1/payments/mercadopago/webhook';
        $preference = $this->mercadoPago->createPreference([
            'items' => [[
                'id' => 'lease-charge-'.$charge->public_id,
                'title' => Str::limit((string) $charge->description, 120, ''),
                'description' => 'Cobrança de locação',
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => round((float) $charge->amount, 2),
            ]],
            'payer' => ['email' => $request->user()->email],
            'external_reference' => $payment->public_id,
            'notification_url' => $webhook,
            'back_urls' => [
                'success' => $frontend.'/?payment=approved&charge='.$charge->public_id,
                'pending' => $frontend.'/?payment=pending&charge='.$charge->public_id,
                'failure' => $frontend.'/?payment=failure&charge='.$charge->public_id,
            ],
            'auto_return' => 'approved',
            'payment_methods' => $this->paymentMethodPolicy($data['method']),
            'metadata' => [
                'app_slug' => $this->context->slug(),
                'source_type' => 'lease_charge',
                'source_reference' => $charge->public_id,
                'recipient_user_id' => (int) $recipient->id,
            ],
        ], 'lease-checkout-'.$payment->public_id.'-'.$data['method']);

        $checkoutUrl = trim((string) ($preference['init_point'] ?? ''));
        if ($checkoutUrl === '') throw new RuntimeException('O provedor não retornou a URL do checkout.');
        $metadata = array_merge($metadata, [
            'lease_id' => $leaseId,
            'charge_id' => $chargeId,
            'recipient_user_id' => (int) $recipient->id,
            'requested_method' => $data['method'],
            'checkout_preference_id' => $preference['id'] ?? null,
            'checkout_url' => $checkoutUrl,
            'sandbox_url' => $preference['sandbox_init_point'] ?? null,
            'checkout_created_at' => now()->toIso8601String(),
        ]);

        DB::transaction(function () use ($payment, $chargeId, $data, $metadata) {
            DB::table('ecosystem_payments')->where('id', $payment->id)->update([
                'method' => $data['method'],
                'status' => 'pending',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            DB::table('lease_charges')->where('id', $chargeId)->update([
                'ecosystem_payment_id' => $payment->id,
                'payment_method' => $data['method'],
                'provider' => 'mercadopago',
                'status' => 'processing',
                'metadata' => json_encode(['checkout_preference_id' => $metadata['checkout_preference_id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'payment' => DB::table('ecosystem_payments')->find($payment->id),
            'charge' => DB::table('lease_charges')->find($chargeId),
            'checkout_url' => $checkoutUrl,
            'preference_id' => $preference['id'] ?? null,
        ]);
    }

    private function paymentMethodPolicy(string $method): array
    {
        // Checkout Pro always keeps account-money available. The exclusions
        // narrow the experience toward the method selected in the application.
        return match ($method) {
            'pix' => ['excluded_payment_types' => array_map(fn ($id) => ['id' => $id], ['credit_card', 'debit_card', 'prepaid_card', 'ticket', 'atm'])],
            'boleto' => ['excluded_payment_types' => array_map(fn ($id) => ['id' => $id], ['credit_card', 'debit_card', 'prepaid_card', 'bank_transfer', 'atm'])],
            'card' => ['excluded_payment_types' => array_map(fn ($id) => ['id' => $id], ['ticket', 'bank_transfer', 'atm'])],
            default => [],
        };
    }

    private function leaseForUser(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        $allowed = (int) $lease->landlord_user_id === $userId
            || (int) $lease->tenant_user_id === $userId
            || ($lease->tenant_email && strcasecmp((string) $lease->tenant_email, (string) $request->user()->email) === 0)
            || $admin;
        abort_unless($allowed, 403);
        return $lease;
    }
}
