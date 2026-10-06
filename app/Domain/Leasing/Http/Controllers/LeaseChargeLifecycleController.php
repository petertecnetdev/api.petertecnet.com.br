<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseDetailService;
use App\Domain\Leasing\Services\LeasePaymentService;
use App\Domain\Leasing\Services\LeaseReadinessService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseChargeLifecycleController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseReadinessService $readiness,
        private readonly LeasePaymentService $payments,
        private readonly LeaseDetailService $details,
    ) {}

    public function markPaid(Request $request, int $leaseId, int $chargeId)
    {
        $lease = $this->managedLease($request, $leaseId);
        $data = $request->validate([
            'payment_method' => 'nullable|in:pix,boleto,card,cash,transfer,other',
        ]);

        $charge = $this->payments->markPaid(
            $this->context->id(),
            $this->context->slug(),
            (int) $request->user()->id,
            $leaseId,
            $chargeId,
            $data['payment_method'] ?? null,
        );

        if ($lease->status === 'active') {
            return response()->json($charge);
        }

        $checklist = $this->readiness->checklist($lease);
        if (! $checklist['ready'] || ! $this->readiness->signaturesComplete($lease) || ! $this->readiness->initialPaymentsComplete($lease)) {
            return response()->json($charge);
        }

        $metadata = $this->decode($lease->metadata);
        $metadata['workflow']['stage'] = 'active';
        $metadata['workflow']['activated_at'] = now()->toIso8601String();
        $metadata['workflow']['activation_source'] = 'initial_payments_completed';
        $appId = $this->context->id();

        DB::transaction(function () use ($appId, $lease, $leaseId, $metadata) {
            DB::table('leases')->where('app_id', $appId)->where('id', $leaseId)->update([
                'status' => 'active',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            DB::table('properties')->where('app_id', $appId)->where('id', $lease->property_id)->update([
                'status' => 'occupied',
                'updated_at' => now(),
            ]);
        });

        return response()->json($this->details->payload($appId, $leaseId));
    }

    private function managedLease(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless(
            (int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request),
            403
        );

        return $lease;
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }

    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! $value) {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
