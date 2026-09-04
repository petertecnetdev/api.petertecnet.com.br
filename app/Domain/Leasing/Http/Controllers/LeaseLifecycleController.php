<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseLifecycleCommandService;
use App\Domain\Leasing\Services\LeaseLifecycleReadService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseLifecycleController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseLifecycleReadService $readModel,
        private readonly LeaseLifecycleCommandService $commands,
    ) {}

    public function index(Request $request)
    {
        return response()->json($this->readModel->workspace(
            $this->context->id(),
            (int) $request->user()->id,
            (string) $request->user()->email,
            $request->only(['vigency', 'ending_within', 'q']),
        ));
    }

    public function propertyTimeline(Request $request, int $propertyId)
    {
        $property = $this->assertPropertyOwner($request, $propertyId);

        return response()->json($this->readModel->propertyTimeline($this->context->id(), $property));
    }

    public function renew(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate([
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'rent_amount' => 'nullable|numeric|min:0.01',
            'due_day' => 'nullable|integer|min:1|max:31',
            'adjustment_index' => 'nullable|string|max:40',
            'adjustment_frequency_months' => 'nullable|integer|min:1|max:120',
        ]);

        $created = $this->commands->renew($this->context->id(), $lease, $data);

        return response()->json(['lease' => $this->readModel->augmentLease($created)], 201);
    }

    public function terminate(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate([
            'effective_on' => 'nullable|date|before_or_equal:today',
            'reason' => 'nullable|string|max:1000',
        ]);

        $updated = $this->commands->terminate(
            $this->context->id(),
            $lease,
            $data,
            (int) $request->user()->id,
        );

        return response()->json(['lease' => $this->readModel->augmentLease($updated)]);
    }

    private function assertPropertyOwner(Request $request, int $propertyId): object
    {
        $property = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('id', $propertyId)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($property, 404, 'Imóvel não encontrado.');
        abort_unless((int) $property->owner_user_id === (int) $request->user()->id, 403);

        return $property;
    }

    private function assertLeaseManager(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($lease, 404, 'Locação não encontrada.');
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id, 403);

        return $lease;
    }
}
