<?php

declare(strict_types=1);

namespace App\Services\Independence;

use App\Models\Artist;
use App\Models\Event;
use App\Models\Independence\OwnershipClaim;
use App\Models\Production;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Persistence for claim intake and scoped moderation queues; HTTP validation lives in controllers. */
final class OwnershipClaimAccessService
{
    /** @return array{claim: OwnershipClaim|null, created: bool} */
    public function submit(int $appId, User $actor, array $input): array
    {
        $key = hash('sha256', implode(':', [
            $appId, $input['entity_type'], $input['entity_id'], $actor->getKey(),
        ]));

        try {
            return DB::transaction(function () use ($appId, $actor, $input, $key): array {
                $entity = $this->findEntity($input['entity_type'], (int) $input['entity_id'], $appId);
                if (! $this->isClaimable($input['entity_type'], $entity)) {
                    return ['claim' => null, 'created' => false];
                }
                $existing = OwnershipClaim::query()->where('active_claim_key', $key)->first();
                if ($existing) {
                    return ['claim' => $existing, 'created' => false];
                }

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

                // Intake never grants editing, ownership or commercial privileges.
                return ['claim' => $claim, 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent identical submissions are idempotent; other constraints must fail.
            $existing = OwnershipClaim::query()->where('active_claim_key', $key)->first();
            if (! $existing) throw $exception;
            return ['claim' => $existing, 'created' => false];
        }
    }

    public function mine(int $appId, int $userId): LengthAwarePaginator
    {
        return OwnershipClaim::query()->where('app_id', $appId)
            ->where('claimant_user_id', $userId)->latest('id')->paginate(20, [
                'id', 'entity_type', 'entity_id', 'status', 'created_at', 'updated_at',
            ]);
    }

    public function forReview(int $appId, ?string $status, int $perPage): LengthAwarePaginator
    {
        $claims = OwnershipClaim::query()->where('app_id', $appId)
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->latest('id')->paginate($perPage);

        $grantIds = DB::table('independence_entity_grants')->where('app_id', $appId)
            ->whereNull('revoked_at')
            ->whereIn('approved_claim_id', $claims->getCollection()->pluck('id'))
            ->pluck('id', 'approved_claim_id');
        $claims->getCollection()->each(
            fn (OwnershipClaim $claim) => $claim->setAttribute('grant_id', $grantIds->get($claim->id))
        );
        return $claims;
    }

    private function findEntity(string $type, int $id, int $appId): Event|Production|Artist
    {
        $class = match ($type) {
            'event' => Event::class,
            'production' => Production::class,
            'artist' => Artist::class,
            default => abort(422, 'Tipo de cadastro inválido.'),
        };

        return $class::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($id);
    }

    private function isClaimable(string $type, Event|Production|Artist $entity): bool
    {
        if ($type === 'artist') return ! $entity->user_id && ! $entity->claimed_at;
        // NULL means legacy ownership: never expose producer-owned pages as claimable.
        return Schema::hasColumn($entity->getTable(), 'ownership_status')
            && in_array($entity->ownership_status, ['unclaimed', 'pending_claim'], true);
    }
}
