<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseReadinessService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class LeasePaymentWorkflowController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseReadinessService $readiness,
    ) {}

    public function requestInitialPayment(Request $request, int $leaseId)
    {
        $lease = $this->managedLease($request, $leaseId);
        abort_unless($this->readiness->signaturesComplete($lease), 422, 'As duas partes precisam assinar a versão atual antes da cobrança.');
        $data = $request->validate(['registration_url' => 'required|url|max:500']);
        $metadata = $this->decode($lease->metadata);
        $agreement = data_get($metadata, 'workflow.initial_payment', []);
        $collectDeposit = $agreement['collect_deposit'] ?? ((float) $lease->deposit_amount > 0);
        $collectFirstRent = $agreement['collect_first_rent'] ?? true;
        $additional = collect($agreement['additional_charges'] ?? [])->filter(fn ($item) => (float) ($item['amount'] ?? 0) > 0);
        $charges = [];

        DB::transaction(function () use ($lease, $leaseId, $collectDeposit, $collectFirstRent, $additional, &$charges, &$metadata) {
            if ($collectDeposit && (float) $lease->deposit_amount > 0) {
                $charges[] = $this->ensureCharge($leaseId, 'deposit', 'Caução da locação', (float) $lease->deposit_amount, now()->toDateString(), 'deposit');
            }
            if ($collectFirstRent && (float) $lease->rent_amount > 0) {
                $charges[] = $this->ensureCharge($leaseId, 'rent', 'Primeiro aluguel', (float) $lease->rent_amount, $lease->starts_on ?: now()->toDateString(), 'first_rent');
            }
            foreach ($additional as $index => $charge) {
                $charges[] = $this->ensureCharge(
                    $leaseId,
                    'other',
                    (string) $charge['description'],
                    (float) $charge['amount'],
                    $charge['due_date'] ?? ($lease->starts_on ?: now()->toDateString()),
                    'additional_'.($index + 1)
                );
            }

            $metadata['workflow']['stage'] = count($charges) ? 'awaiting_initial_payment' : 'ready_to_activate';
            $metadata['workflow']['payment_requested_at'] = now()->toIso8601String();
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
                'metadata' => $this->json($metadata), 'updated_at' => now(),
            ]);
        });

        $paymentUrl = rtrim($data['registration_url'], '/').'/?lease='.$leaseId.'&pay=1';
        if (!empty($lease->tenant_email) && count($charges)) {
            try {
                Mail::raw(
                    "Olá {$lease->tenant_name},\n\nO pacote contratual foi assinado e os valores iniciais definidos no acordo estão disponíveis para pagamento.\n\nAcesse: {$paymentUrl}",
                    fn ($message) => $message->to($lease->tenant_email, $lease->tenant_name)->subject('Valores iniciais da locação disponíveis')
                );
            } catch (Throwable $e) { report($e); }
        }

        return response()->json([
            'ok' => true,
            'charges' => $charges,
            'stage' => count($charges) ? 'awaiting_initial_payment' : 'ready_to_activate',
            'agreement' => $agreement,
        ]);
    }

    private function ensureCharge(int $leaseId, string $type, string $description, float $amount, string $dueDate, string $agreementKey): object
    {
        $existing = DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->where('lease_id', $leaseId)
            ->whereIn('status', ['pending', 'processing', 'paid'])
            ->where('metadata', 'like', '%"agreement_key":"'.$agreementKey.'"%')
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
            'metadata' => $this->json(['source' => 'lease_onboarding', 'agreement_key' => $agreementKey]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return DB::table('lease_charges')->where('id', $id)->first();
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->firstOrFail();
        $isAdmin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $isAdmin, 403);
        return $lease;
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
