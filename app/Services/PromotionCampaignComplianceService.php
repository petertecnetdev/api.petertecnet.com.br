<?php

namespace App\Services;

use App\Models\PromotionCampaign;
use Illuminate\Validation\ValidationException;

class PromotionCampaignComplianceService
{
    private const REGULATED_TYPES = ['contest', 'draw', 'giveaway'];

    public function classification(array $payload): array
    {
        $type = strtolower((string) ($payload['type'] ?? 'poll'));
        $reward = is_array($payload['reward'] ?? null) ? $payload['reward'] : [];
        $rewardMode = strtolower((string) ($reward['mode'] ?? 'none'));
        $hasPrize = (bool) ($reward['has_prize'] ?? false) || in_array($rewardMode, ['winner', 'selected'], true);
        $requires = in_array($type, self::REGULATED_TYPES, true) || ($hasPrize && $rewardMode !== 'all_eligible');

        return [
            'requires_authorization' => $requires,
            'compliance_status' => $requires ? 'review' : 'not_required',
            'reason' => $requires
                ? 'Campanha com premiação seletiva, sorte ou competição exige validação regulatória antes da publicação.'
                : 'Mecânica sem seleção aleatória/competitiva ou benefício objetivo para todos os elegíveis.',
        ];
    }

    public function assertPublishable(PromotionCampaign $campaign): void
    {
        if (! $campaign->requires_authorization) return;

        if ($campaign->compliance_status !== 'approved' || ! $campaign->authorization_number) {
            throw ValidationException::withMessages([
                'compliance' => ['A campanha exige validação regulatória aprovada e número de autorização antes da publicação.'],
            ]);
        }
    }

    public function assertSystemDrawAllowed(PromotionCampaign $campaign): void
    {
        $this->assertPublishable($campaign);
        $method = strtolower((string) data_get($campaign->authorization_metadata, 'selection_method'));
        if ($method !== 'system_random') {
            throw ValidationException::withMessages([
                'selection_method' => ['A autorização desta campanha não permite seleção aleatória pelo sistema.'],
            ]);
        }
    }
}
