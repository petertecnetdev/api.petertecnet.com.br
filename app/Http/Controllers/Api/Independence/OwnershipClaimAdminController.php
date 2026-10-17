<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Independence;

use App\Http\Controllers\Controller;
use App\Services\Independence\OwnershipClaimReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Independence\OwnershipClaimAccessService;

final class OwnershipClaimAdminController extends Controller
{
    public function index(Request $request, OwnershipClaimAccessService $claims): JsonResponse
    {
        $input = $request->validate([
            'status' => ['nullable', 'in:pending,needs_info,rejected,approved'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $claims->forReview(
            (int) $request->attributes->get('app_id'),
            $input['status'] ?? null,
            (int) ($input['per_page'] ?? 25)
        );
        return response()->json(['success' => true, 'data' => $page]);
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
