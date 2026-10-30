<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Independence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Independence\SubmitOwnershipClaimRequest;
use App\Models\Independence\OwnershipClaim;
use App\Services\Independence\OwnershipClaimAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnershipClaimIntakeController extends Controller
{
    public function store(SubmitOwnershipClaimRequest $request, OwnershipClaimAccessService $claims): JsonResponse
    {
        $appId = (int) $request->attributes->get('app_id');
        $actor = $request->user('api');
        $input = $request->validated();
        abort_unless($appId > 0 && $actor, 403);
        abort_unless($actor->email_verified_at, 403, 'Confirme seu e-mail antes de reivindicar o perfil.');
        abort_unless(strcasecmp((string) $actor->email, (string) $input['email']) === 0, 403,
            'O e-mail informado deve ser o e-mail verificado da sua conta.');

        $result = $claims->submit($appId, $actor, $input);
        if (! $result['claim']) {
            return response()->json([
                'success' => false,
                'message' => 'Este cadastro já possui responsável. Solicite suporte para uma contestação.',
                'code' => 'OWNERSHIP_NOT_CLAIMABLE',
            ], 409);
        }

        return $this->receipt($result['claim'], $result['created']);
    }

    public function mine(Request $request, OwnershipClaimAccessService $claims): JsonResponse
    {
        $page = $claims->mine(
            (int) $request->attributes->get('app_id'),
            (int) $request->user('api')->getKey()
        );
        return response()->json(['success' => true, 'data' => $page]);
    }

    private function receipt(OwnershipClaim $claim, bool $created): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $created
                ? 'Solicitação recebida. Nossa equipe verificará sua identidade antes de conceder qualquer acesso.'
                : 'Já existe uma solicitação em análise para este cadastro.',
            'data' => [
                'id' => $claim->id,
                'entity_type' => $claim->entity_type,
                'entity_id' => $claim->entity_id,
                'status' => $claim->status,
            ],
        ], $created ? 201 : 200);
    }

}
