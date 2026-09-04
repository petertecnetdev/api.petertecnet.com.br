<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Documents\DTOs\DocumentAuditContext;
use App\Domain\Leasing\Services\LeaseTerminationService;
use App\Domain\Notifications\Exceptions\NotificationDispatchException;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseTerminationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseTerminationService $terminations,
    ) {}

    public function show(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);

        return response()->json($this->terminations->state($this->context->id(), $leaseId));
    }

    public function complete(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        $data = $request->validate([
            'checklist' => 'required|array',
            'ended_on' => 'required|date|before_or_equal:today',
            'reason' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:10000',
            'keys_returned' => 'required|accepted',
            'keys_returned_on' => 'required|date|before_or_equal:today',
            'keys_quantity' => 'nullable|integer|min:1|max:100',
            'key_notes' => 'nullable|string|max:5000',
            'exit_inspection_id' => 'nullable|integer|min:1',
            'final_charge_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'termination_penalty_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'damage_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'outstanding_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'deposit_refund_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'deposit_applied_amount' => 'nullable|numeric|min:0|max:9999999999.99',
            'deposit_settlement' => 'required|in:refunded,applied,retained,pending,not_applicable',
            'utilities_notes' => 'nullable|string|max:5000',
            'mutual_release' => 'nullable|boolean',
            'cancel_future_rent_charges' => 'nullable|boolean',
            'confirm_end' => 'required|accepted',
        ]);

        return response()->json($this->terminations->complete(
            $this->context->id(),
            $this->context->slug(),
            $lease,
            $data,
            (int) $request->user()->id,
            $this->auditContext($request),
        ));
    }

    public function send(Request $request, int $leaseId)
    {
        $this->assertLeaseManager($request, $leaseId);

        try {
            return response()->json($this->terminations->send(
                $this->context->id(),
                $leaseId,
                (int) $request->user()->id,
                $this->auditContext($request),
            ));
        } catch (NotificationDispatchException $e) {
            report($e);

            return response()->json([
                'message' => 'O distrato não foi enviado. As solicitações pendentes foram invalidadas com segurança; verifique a configuração de e-mail.',
            ], 502);
        }
    }

    public function timeline(Request $request, int $leaseId)
    {
        $this->assertLeaseAccess($request, $leaseId);

        return response()->json($this->terminations->timeline($this->context->id(), $leaseId));
    }

    private function assertLeaseAccess(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $userId = (int) $request->user()->id;
        $allowed = (int) $lease->landlord_user_id === $userId
            || (int) $lease->tenant_user_id === $userId
            || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0)
            || $this->isAdmin($request);
        abort_unless($allowed, 403);

        return $lease;
    }

    private function assertLeaseManager(Request $request, int $leaseId): object
    {
        $lease = $this->assertLeaseAccess($request, $leaseId);
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id || $this->isAdmin($request), 403);

        return $lease;
    }

    private function auditContext(Request $request): DocumentAuditContext
    {
        return new DocumentAuditContext(
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            source: 'lease_termination_http',
            requestId: $request->header('X-Request-Id'),
        );
    }

    private function isAdmin(Request $request): bool
    {
        return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
    }
}
