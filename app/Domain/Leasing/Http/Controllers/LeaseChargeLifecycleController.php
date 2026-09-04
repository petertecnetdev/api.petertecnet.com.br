<?php

namespace App\Domain\Leasing\Http\Controllers;

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
    ) {}

    public function markPaid(Request $request, int $leaseId, int $chargeId)
    {
        $response = app(LeasingController::class)->markChargePaid($request, $leaseId, $chargeId);

        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->first();

        if (!$lease || $lease->status === 'active') {
            return $response;
        }

        $checklist = $this->readiness->checklist($lease);
        if (!$checklist['ready'] || !$this->readiness->signaturesComplete($lease) || !$this->readiness->initialPaymentsComplete($lease)) {
            return $response;
        }

        $metadata = $this->decode($lease->metadata);
        $metadata['workflow']['stage'] = 'active';
        $metadata['workflow']['activated_at'] = now()->toIso8601String();
        $metadata['workflow']['activation_source'] = 'initial_payments_completed';

        DB::transaction(function () use ($lease, $leaseId, $metadata) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->update([
                'status' => 'active',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
            DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)->update([
                'status' => 'occupied',
                'updated_at' => now(),
            ]);
        });

        return app(LeasingController::class)->showLease($request, $leaseId);
    }

    private function decode($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        if (!$value) return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
