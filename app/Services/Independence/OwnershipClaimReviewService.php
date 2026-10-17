<?php

declare(strict_types=1);

namespace App\Services\Independence;

use App\Domain\Platform\Services\ApplicationAdminService;
use App\Models\AppNotification;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Independence\OwnershipClaim;
use App\Models\Production;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Human decision + narrow management grant are committed as one transaction.
 * No request field can select application, claimant, owner or commerce rights.
 */
final class OwnershipClaimReviewService
{
    public function review(int $appId, int $claimId, User $reviewer, array $decision): OwnershipClaim
    {
        abort_unless($appId > 0
            && app(ApplicationAdminService::class)->hasPermission($reviewer, $appId, 'moderation.manage'),
            403, 'Sem permissão de revisão nesta aplicação.');

        return DB::transaction(function () use ($appId, $claimId, $reviewer, $decision): OwnershipClaim {
            $claim = OwnershipClaim::query()
                ->where('app_id', $appId)
                ->lockForUpdate()
                ->findOrFail($claimId);

            abort_unless(in_array($claim->status, ['pending', 'needs_info'], true), 409,
                'Esta reivindicação já foi concluída.');

            $newStatus = $decision['decision'];
            $method = $decision['verification_method'] ?? null;
            $evidence = trim((string) ($decision['evidence_reference'] ?? ''));
            if ($newStatus === 'approved') {
                abort_unless((int) $reviewer->getKey() !== (int) $claim->claimant_user_id, 403,
                    'Você não pode aprovar sua própria reivindicação.');
                $this->approve($appId, $claim, $reviewer);
            }

            DB::table('independence_claim_audits')->insert([
                'app_id' => $appId,
                'claim_id' => $claim->getKey(),
                'actor_user_id' => $reviewer->getKey(),
                'previous_status' => $claim->status,
                'decision' => $newStatus,
                'verification_method' => $newStatus === 'approved' ? $method : null,
                'evidence_digest' => $newStatus === 'approved' ? hash('sha256', $evidence) : null,
                'review_note' => trim($decision['review_note']),
                'created_at' => now(),
            ]);

            $claim->forceFill([
                'status' => $newStatus,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'review_note' => trim($decision['review_note']),
                'reviewed_at' => now(),
                'active_claim_key' => in_array($newStatus, ['rejected', 'approved'], true)
                    ? null : $claim->active_claim_key,
            ])->saveOrFail();

            {
                AppNotification::query()->create([
                    'app_id' => $appId,
                    'user_id' => $claim->claimant_user_id,
                    'type' => 'independence_claim_review',
                    'title' => match ($newStatus) {
                        'approved' => 'Reivindicação aprovada',
                        'needs_info' => 'Informações adicionais solicitadas',
                        default => 'Reivindicação analisada',
                    },
                    'message' => $newStatus === 'approved'
                        ? 'Sua titularidade foi verificada. O acesso é restrito à página aprovada; vendas exigem liberação separada.'
                        : ($newStatus === 'needs_info'
                            ? 'Precisamos de informações adicionais sobre seu vínculo. Consulte a Central de Ajuda e informe o protocolo da solicitação.'
                            : 'Sua solicitação foi analisada. Consulte o suporte para esclarecimentos.'),
                    'reference_type' => $claim->entity_type,
                    'reference_id' => $claim->entity_id,
                    'data' => ['claim_id' => $claim->id, 'status' => $newStatus],
                ]);
            }

            return $claim->fresh();
        }, 3);
    }

    /**
     * Fail-closed revocation of a scoped grant. A historical artist identity
     * must be contested through a separate, manually verified process.
     */
    public function revoke(int $appId, int $grantId, User $reviewer, string $reason): void
    {
        abort_unless($appId > 0
            && app(ApplicationAdminService::class)->hasPermission($reviewer, $appId, 'moderation.manage'),
            403, 'Sem permissão de revisão nesta aplicação.');
        abort_unless(mb_strlen(trim($reason)) >= 10, 422, 'Informe o motivo da revogação.');

        DB::transaction(function () use ($appId, $grantId, $reviewer, $reason): void {
            $grant = DB::table('independence_entity_grants')
                ->where('app_id', $appId)
                ->where('id', $grantId)
                ->lockForUpdate()
                ->first();
            abort_unless($grant, 404);
            abort_unless($grant->revoked_at === null, 409, 'Concessão já revogada.');
            abort_unless((int) $grant->user_id !== (int) $reviewer->id, 403,
                'A pessoa beneficiária não pode revisar sua própria concessão.');

            $entity = match ($grant->entity_type) {
                'production' => Production::query()->where('app_id', $appId)
                    ->lockForUpdate()->findOrFail($grant->entity_id),
                'event' => Event::query()->where('app_id', $appId)
                    ->lockForUpdate()->findOrFail($grant->entity_id),
                default => abort(422, 'Tipo inválido para revogação automática.'),
            };

            abort_unless($entity->ownership_status === 'verified'
                && $entity->verification_status === 'manual_approved', 409,
                'A titularidade mudou; requer análise individual antes de revogar.');

            DB::table('independence_entity_grants')->where('id', $grantId)->update([
                'revoked_at' => now(), 'active_entity_key' => null, 'updated_at' => now(),
            ]);
            $entity->forceFill([
                'ownership_status' => 'unclaimed',
                'ownership_verified_at' => null,
                'verification_status' => null,
                'commerce_authorized_at' => null,
            ])->saveOrFail();

            DB::table('independence_claim_audits')->insert([
                'app_id' => $appId,
                'claim_id' => $grant->approved_claim_id,
                'actor_user_id' => $reviewer->getKey(),
                'previous_status' => 'approved',
                'decision' => 'revoked',
                'verification_method' => null,
                'evidence_digest' => null,
                'review_note' => trim($reason),
                'created_at' => now(),
            ]);

            AppNotification::query()->create([
                'app_id' => $appId,
                'user_id' => $grant->user_id,
                'type' => 'independence_claim_review',
                'title' => 'Acesso à página revogado',
                'message' => 'O acesso à página foi revogado após revisão administrativa. Entre em contato com o suporte para esclarecimentos.',
                'reference_type' => $grant->entity_type,
                'reference_id' => $grant->entity_id,
                'data' => ['claim_id' => $grant->approved_claim_id, 'status' => 'revoked'],
            ]);
        }, 3);
    }

    private function approve(int $appId, OwnershipClaim $claim, User $reviewer): void
    {
        $user = User::query()->whereKey($claim->claimant_user_id)->lockForUpdate()->firstOrFail();
        abort_unless($user->email_verified_at && $claim->contact_verified_at
            && strcasecmp((string) $user->email, (string) $claim->contact_email) === 0,
            409, 'A identidade de contato não está mais verificada. Solicite nova conferência.');

        $type = $claim->entity_type;
        $entity = match ($type) {
            'artist' => Artist::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($claim->entity_id),
            'production' => Production::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($claim->entity_id),
            'event' => Event::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($claim->entity_id),
            default => throw new HttpException(422, 'Tipo de cadastro inválido.'),
        };

        if ($entity instanceof Artist) {
            abort_unless(! $entity->user_id && ! $entity->claimed_at, 409,
                'Este artista já possui titularidade.');
            // A personal artist identity uses the existing single owner contract.
            $entity->forceFill([
                'user_id' => $user->getKey(),
                'claimed_at' => now(),
                'verification_status' => 'verified',
                'verified_at' => now(),
            ])->saveOrFail();
            return;
        }

        abort_unless(in_array($entity->ownership_status, ['unclaimed', 'pending_claim'], true)
            && filled($entity->listing_source) && filled($entity->source_url),
            409, 'A ficha não tem origem curatorial auditável ou já foi reivindicada.');

        if ($entity instanceof Event) {
            abort_unless($entity->production_id && Production::query()
                ->where('app_id', $appId)->whereKey($entity->production_id)->exists(),
                409, 'O evento não possui uma produção válida nesta aplicação.');
        }

        $activeKey = $appId.':'.$type.':'.$entity->getKey();
        abort_if(DB::table('independence_entity_grants')
            ->where('active_entity_key', $activeKey)->whereNull('revoked_at')->exists(),
            409, 'Esta página já possui uma concessão ativa.');

        DB::table('independence_entity_grants')->insert([
            'active_entity_key' => $activeKey,
            'app_id' => $appId,
            'entity_type' => $type,
            'entity_id' => $entity->getKey(),
            'user_id' => $user->getKey(),
            'approved_claim_id' => $claim->getKey(),
            'approved_by_user_id' => $reviewer->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A curated entity keeps its original institutional author for audit.
        // Granting one production NEVER grants the producer's separate events.
        $entity->forceFill([
            'ownership_status' => 'verified',
            'ownership_verified_at' => now(),
            'verification_status' => 'manual_approved',
            // Independent commerce onboarding is mandatory and stays closed.
            'commerce_authorized_at' => null,
        ])->saveOrFail();
    }
}
