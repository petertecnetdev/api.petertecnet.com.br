<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Independence;

use App\Http\Controllers\Controller;
use App\Models\Independence\OwnershipClaim;
use App\Services\Independence\OwnershipClaimReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $grantIds = DB::table('independence_entity_grants')
            ->where('app_id', (int) $request->attributes->get('app_id'))
            ->whereNull('revoked_at')
            ->whereIn('approved_claim_id', $claims->getCollection()->pluck('id'))
            ->pluck('id', 'approved_claim_id');
        $claims->getCollection()->each(fn ($claim) => $claim->setAttribute('grant_id', $grantIds->get($claim->id)));
        return response()->json(['success' => true, 'data' => $claims]);
    }

    public function review(Request $request, int $claimId, OwnershipClaimReviewService $reviews): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:approved,rejected,needs_info'],
            'review_note' => ['required', 'string', 'min:5', 'max:2000'],
            'verification_method' => ['required_if:decision,approved', 'nullable',
                'in:official_channel,corporate_domain,documentation'],
            'evidence_reference' => ['required_if:decision,approved', 'nullable',
                'string', 'min:8', 'max:2000'],
            'verification_attested' => ['required_if:decision,approved', 'accepted'],
            'app_id' => ['missing'],
            'entity_id' => ['missing'],
            'claimant_user_id' => ['missing'],
            'user_id' => ['missing'],
            'commerce_authorized_at' => ['missing'],
        ]);

        $claim = $reviews->review(
            (int) $request->attributes->get('app_id'),
            $claimId,
            $request->user('api'),
            $input,
        );

        return response()->json([
            'success' => true,
            'data' => ['id' => $claim->id, 'status' => $claim->status],
            'message' => 'Análise registrada com trilha de auditoria.',
        ]);
    }
    public function revoke(Request $request, int $grantId, OwnershipClaimReviewService $reviews): JsonResponse
    {
        $input = $request->validate([
            'review_note' => ['required', 'string', 'min:10', 'max:2000'],
            'app_id' => ['missing'],
            'user_id' => ['missing'],
            'entity_id' => ['missing'],
        ]);

        $reviews->revoke(
            (int) $request->attributes->get('app_id'),
            $grantId,
            $request->user('api'),
            $input['review_note'],
        );

        return response()->json(['success' => true, 'message' => 'Acesso revogado e auditado.']);
    }

}
