<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Independence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Independence\SubmitOwnershipClaimRequest;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Independence\OwnershipClaim;
use App\Models\Production;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class OwnershipClaimIntakeController extends Controller
{
    public function store(SubmitOwnershipClaimRequest $request): JsonResponse
    {
        $appId = (int) $request->attributes->get('app_id');
        $actor = $request->user('api');
        $input = $request->validated();
        abort_unless($appId > 0 && $actor, 403);
        abort_unless($actor->email_verified_at, 403, 'Confirme seu e-mail antes de reivindicar o perfil.');
        abort_unless(
            strcasecmp((string) $actor->email, (string) $input['email']) === 0,
            403,
            'O e-mail informado deve ser o e-mail verificado da sua conta.'
        );

        $entity = $this->findEntity($input['entity_type'], (int) $input['entity_id'], $appId);
        if (! $this->isUnclaimed($input['entity_type'], $entity)) {
            return response()->json([
                'success' => false,
                'message' => 'Este cadastro já possui responsável. Solicite suporte para uma contestação.',
                'code' => 'OWNERSHIP_NOT_CLAIMABLE',
            ], 409);
        }

        $key = hash('sha256', implode(':', [
            $appId, $input['entity_type'], $entity->getKey(), $actor->getKey(),
        ]));
        $existing = OwnershipClaim::query()->where('active_claim_key', $key)->first();
        if ($existing) return $this->receipt($existing, false);

        try {
            $claim = DB::transaction(function () use ($input, $actor, $appId, $entity, $key): OwnershipClaim {
                $claim = new OwnershipClaim();
                $claim->forceFill([
                    'app_id' => $appId,
                    'entity_type' => $input['entity_type'],
                    'entity_id' => $entity->getKey(),
                    'claimant_user_id' => $actor->getKey(),
                    'contact_name' => $input['name'],
                    'contact_email' => $actor->email,
                    'contact_phone' => $input['phone'] ?? null,
                    'message' => $input['message'] ?? null,
                    'status' => 'pending',
                    'active_claim_key' => $key,
                    'consented_at' => now(),
                    'contact_verified_at' => $actor->email_verified_at,
                ]);
                $claim->saveOrFail();
                return $claim;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->receipt(OwnershipClaim::query()->where('active_claim_key', $key)->firstOrFail(), false);
        }

        // Never grant ownership, access, publication rights or ticket sales on intake.
        return $this->receipt($claim, true);
    }

    public function mine(Request $request): JsonResponse
    {
        $claims = OwnershipClaim::query()
            ->where('app_id', (int) $request->attributes->get('app_id'))
            ->where('claimant_user_id', (int) $request->user('api')->getKey())
            ->latest('id')->paginate(20, [
                'id', 'entity_type', 'entity_id', 'status', 'created_at', 'updated_at',
            ]);
        return response()->json(['success' => true, 'data' => $claims]);
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

    private function findEntity(string $type, int $id, int $appId): Event|Production|Artist
    {
        $model = match ($type) {
            'event' => Event::class,
            'production' => Production::class,
            'artist' => Artist::class,
            default => abort(422, 'Tipo de cadastro inválido.'),
        };

        return $model::query()->where('app_id', $appId)->findOrFail($id);
    }

    private function isUnclaimed(string $type, Event|Production|Artist $entity): bool
    {
        if ($type === 'artist') {
            return ! $entity->user_id && ! $entity->claimed_at;
        }

        // Default NULL belongs to the original legacy flow: existing purchases
        // and authentic producer-owned entities must never turn into "unclaimed".
        // Only explicitly curated/unclaimed listings can be claimed here.
        return Schema::hasColumn($entity->getTable(), 'ownership_status')
            && in_array($entity->ownership_status, ['unclaimed', 'pending_claim'], true);
    }
}
