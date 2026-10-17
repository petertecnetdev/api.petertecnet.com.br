<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Independence;

use App\Http\Controllers\Controller;
use App\Models\Independence\OwnershipClaim;
use App\Services\Independence\OwnershipClaimReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnershipClaimAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', 'in:pending,needs_info,rejected,approved'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $claims = OwnershipClaim::query()->where('app_id', (int) $request->attributes->get('app_id'))
            ->when($input['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate((int) ($input['per_page'] ?? 25));
        return response()->json(['success' => true, 'data' => $claims]);
    }

    public function review(Request $request, int $claimId): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:rejected,needs_info'],
            'review_note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $claim = DB::transaction(function () use ($request, $claimId, $input): OwnershipClaim {
            $claim = OwnershipClaim::query()
                ->where('app_id', (int) $request->attributes->get('app_id'))
                ->lockForUpdate()->findOrFail($claimId);
            abort_unless(in_array($claim->status, ['pending', 'needs_info'], true), 409);
            $claim->forceFill([
                'status' => $input['decision'],
                'reviewed_by_user_id' => $request->user('api')->getKey(),
                'review_note' => $input['review_note'],
                'reviewed_at' => now(),
                'active_claim_key' => $input['decision'] === 'rejected' ? null : $claim->active_claim_key,
            ])->save();
            return $claim->fresh();
        }, 3);

        // Explicitly excludes "approved" until verifiable proof, atomic grants,
        // separate event/production/artist permissions and audit trail exist.
        return response()->json([
            'success' => true,
            'data' => ['id' => $claim->id, 'status' => $claim->status],
        ]);
    }
}
