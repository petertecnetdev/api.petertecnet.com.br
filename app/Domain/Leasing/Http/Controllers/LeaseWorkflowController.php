<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class LeaseWorkflowController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function inviteTenant(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_if(empty($lease->tenant_email), 422, 'Informe o e-mail do inquilino antes de enviar o convite.');

        $data = $request->validate([
            'registration_url' => 'required|url|max:500',
        ]);

        $metadata = $this->decode($lease->metadata);
        $inviteId = (string) Str::uuid();
        $inviteUrl = rtrim($data['registration_url'], '/')
            .'/?invite='.urlencode($inviteId)
            .'&email='.urlencode($lease->tenant_email);

        $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
            'stage' => 'awaiting_tenant_registration',
            'tenant_invitation' => [
                'id' => $inviteId,
                'email' => $lease->tenant_email,
                'sent_at' => now()->toIso8601String(),
            ],
        ]);

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
            'status' => 'awaiting_documents',
            'metadata' => $this->json($metadata),
            'updated_at' => now(),
        ]);

        try {
            Mail::raw(
                "Olá {$lease->tenant_name},\n\nVocê recebeu uma solicitação para participar de uma locação. Crie ou acesse sua conta usando este mesmo e-mail ({$lease->tenant_email}), envie sua documentação e acompanhe a assinatura e os pagamentos pelo link abaixo:\n\n{$inviteUrl}\n\nSe você não reconhece esta solicitação, ignore esta mensagem.",
                function ($message) use ($lease) {
                    $message->to($lease->tenant_email, $lease->tenant_name)
                        ->subject('Convite para completar sua locação');
                }
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'A locação foi preparada, mas o convite por e-mail não pôde ser enviado. Verifique a configuração de e-mail.',
                'invite_url' => $inviteUrl,
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'sent_to' => $lease->tenant_email,
            'invite_url' => $inviteUrl,
            'stage' => 'awaiting_tenant_registration',
        ]);
    }

    public function updateTenantProfile(Request $request, int $leaseId)
    {
        $lease = $this->accessibleLease($request, $leaseId);
        $user = $request->user();
        $isManager = (int) $lease->landlord_user_id === (int) $user->id || $this->isAdmin($request);
        $isTenant = (int) ($lease->tenant_user_id ?? 0) === (int) $user->id
            || (!empty($lease->tenant_email) && strcasecmp((string) $lease->tenant_email, (string) $user->email) === 0);
        abort_unless($isManager || $isTenant, 403);

        $data = $request->validate([
            'tenant_name' => 'nullable|string|max:190',
            'tenant_phone' => 'nullable|string|max:40',
            'tenant_tax_id' => 'nullable|string|max:32',
            'tenant_profile' => 'required|array',
            'tenant_profile.birthdate' => 'nullable|date',
            'tenant_profile.birthplace' => 'nullable|string|max:190',
            'tenant_profile.document_type' => 'nullable|string|max:40',
            'tenant_profile.document_number' => 'nullable|string|max:80',
            'tenant_profile.document_issuer' => 'nullable|string|max:80',
            'tenant_profile.parent_1' => 'nullable|string|max:190',
            'tenant_profile.parent_2' => 'nullable|string|max:190',
            'tenant_profile.marital_status' => 'nullable|string|max:80',
            'tenant_profile.occupation' => 'nullable|string|max:120',
            'tenant_profile.address' => 'nullable|string|max:255',
            'tenant_profile.city' => 'nullable|string|max:120',
            'tenant_profile.state' => 'nullable|string|size:2',
            'tenant_profile.postal_code' => 'nullable|string|max:12',
        ]);

        $metadata = $this->decode($lease->metadata);
        $metadata['tenant_profile'] = array_merge($metadata['tenant_profile'] ?? [], $data['tenant_profile']);
        $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
            'stage' => 'awaiting_documents',
            'tenant_profile_updated_at' => now()->toIso8601String(),
        ]);

        $update = [
            'metadata' => $this->json($metadata),
            'status' => 'awaiting_documents',
            'updated_at' => now(),
        ];
        foreach (['tenant_name', 'tenant_phone', 'tenant_tax_id'] as $field) {
            if (array_key_exists($field, $data)) $update[$field] = $data[$field];
        }
        if ($isTenant && empty($lease->tenant_user_id)) $update['tenant_user_id'] = $user->id;

        DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update($update);

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    public function requestInitialPayment(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $signatureParties = DB::table('lease_signatures')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->where('contract_version', $lease->contract_version)
            ->distinct()
            ->pluck('party');
        abort_unless($signatureParties->contains('landlord') && $signatureParties->contains('tenant'), 422, 'As duas partes precisam assinar antes da solicitação de pagamento.');

        $data = $request->validate([
            'registration_url' => 'required|url|max:500',
            'collect_deposit' => 'sometimes|boolean',
            'collect_first_rent' => 'sometimes|boolean',
        ]);
        $collectDeposit = $data['collect_deposit'] ?? true;
        $collectFirstRent = $data['collect_first_rent'] ?? true;
        $charges = [];

        DB::transaction(function () use ($lease, $leaseId, $collectDeposit, $collectFirstRent, &$charges) {
            if ($collectDeposit && (float) $lease->deposit_amount > 0) {
                $charges[] = $this->ensureCharge($leaseId, 'deposit', 'Caução da locação', (float) $lease->deposit_amount, now()->toDateString());
            }
            if ($collectFirstRent && (float) $lease->rent_amount > 0) {
                $dueDate = $lease->starts_on ?: now()->toDateString();
                $charges[] = $this->ensureCharge($leaseId, 'rent', 'Primeiro aluguel', (float) $lease->rent_amount, $dueDate);
            }

            $metadata = $this->decode($lease->metadata);
            $metadata['workflow'] = array_merge($metadata['workflow'] ?? [], [
                'stage' => 'awaiting_initial_payment',
                'payment_requested_at' => now()->toIso8601String(),
            ]);
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
                'metadata' => $this->json($metadata),
                'updated_at' => now(),
            ]);
        });

        $paymentUrl = rtrim($data['registration_url'], '/').'/?lease='.$leaseId.'&pay=1';
        if (!empty($lease->tenant_email)) {
            try {
                Mail::raw(
                    "Olá {$lease->tenant_name},\n\nO contrato foi assinado e os valores iniciais acordados já estão disponíveis para pagamento na sua locação.\n\nAcesse: {$paymentUrl}",
                    function ($message) use ($lease) {
                        $message->to($lease->tenant_email, $lease->tenant_name)->subject('Pagamento da locação disponível');
                    }
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['ok' => true, 'charges' => $charges, 'stage' => 'awaiting_initial_payment']);
    }

    private function ensureCharge(int $leaseId, string $type, string $description, float $amount, string $dueDate): object
    {
        $existing = DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->where('type', $type)
            ->where('description', $description)
            ->whereIn('status', ['pending', 'processing', 'paid'])
            ->first();
        if ($existing) return $existing;

        $id = DB::table('lease_charges')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $this->context->id(),
            'lease_id' => $leaseId,
            'type' => $type,
            'description' => $description,
            'reference_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'amount' => round($amount, 2),
            'status' => 'pending',
            'payment_method' => null,
            'metadata' => $this->json(['source' => 'lease_onboarding']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return DB::table('lease_charges')->where('id', $id)->first();
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = $this->accessibleLease($request, $leaseId);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);
        return $lease;
    }

    private function accessibleLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $user = $request->user();
        $allowed = (int) $lease->landlord_user_id === (int) $user->id
            || (int) ($lease->tenant_user_id ?? 0) === (int) $user->id
            || (!empty($lease->tenant_email) && strcasecmp((string) $lease->tenant_email, (string) $user->email) === 0)
            || $this->isAdmin($request);
        abort_unless($allowed, 403);
        return $lease;
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (!$value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json($value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
