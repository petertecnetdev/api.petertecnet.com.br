<?php

namespace App\Services;

use App\Models\CommerceOrder;
use App\Models\PromotionCampaign;
use App\Models\PromotionCampaignAttribution;
use App\Models\PromotionCampaignParticipation;
use App\Models\PromotionCampaignReward;
use App\Models\PromotionCampaignWinner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromotionCampaignService
{
    public function __construct(private PromotionCampaignComplianceService $compliance) {}

    public function participate(PromotionCampaign $campaign, int $userId, array $data): array
    {
        if (! $campaign->isActive()) throw ValidationException::withMessages(['campaign' => ['Esta campanha não está disponível para participação.']]);

        return DB::transaction(function () use ($campaign, $userId, $data) {
            $locked = PromotionCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            if (! $locked->isActive()) throw ValidationException::withMessages(['campaign' => ['Esta campanha foi encerrada ou pausada.']]);

            $optionId = $data['option_id'] ?? null;
            if ($locked->type === 'poll' && (! $optionId || ! $locked->options()->whereKey($optionId)->exists())) {
                throw ValidationException::withMessages(['option_id' => ['Selecione uma opção válida.']]);
            }

            $participation = PromotionCampaignParticipation::query()->firstOrCreate(
                ['campaign_id'=>$locked->id, 'user_id'=>$userId, 'action'=>'participate'],
                ['option_id'=>$optionId,'status'=>'valid','source'=>$data['source'] ?? null,'referral_code'=>$data['referral_code'] ?? null,'idempotency_key'=>$data['idempotency_key'] ?? null,'metadata'=>$data['metadata'] ?? null]
            );

            if (! $participation->wasRecentlyCreated) return ['participation'=>$participation, 'reward'=>$locked->rewards()->where('user_id',$userId)->first(), 'duplicate'=>true];
            if ($optionId) $locked->options()->whereKey($optionId)->increment('votes_count');

            $reward = $this->issueConfiguredReward($locked, $userId, ['all_eligible']);
            PromotionCampaignAttribution::query()->create(['campaign_id'=>$locked->id,'user_id'=>$userId,'touchpoint'=>'participation','idempotency_key'=>'participation:'.$participation->id,'metadata'=>['source'=>$data['source'] ?? null]]);
            return ['participation'=>$participation->fresh(), 'reward'=>$reward, 'duplicate'=>false];
        });
    }

    private function issueConfiguredReward(PromotionCampaign $campaign, int $userId, array $allowedModes): ?PromotionCampaignReward
    {
        $reward = $campaign->reward ?: [];
        $mode = (string) ($reward['mode'] ?? 'none');
        if (! in_array($mode, $allowedModes, true)) return null;

        $kind = (string) ($reward['kind'] ?? 'benefit');
        $prefix = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $campaign->app_slug ?: 'PROMO'), 0, 6));
        $code = in_array($kind, ['coupon','discount'], true) ? $prefix.'-'.strtoupper(Str::random(10)) : null;

        return PromotionCampaignReward::query()->firstOrCreate(
            ['campaign_id'=>$campaign->id,'user_id'=>$userId],
            ['kind'=>$kind,'code'=>$code,'value'=>isset($reward['value']) ? (float) $reward['value'] : null,'status'=>'issued','expires_at'=>$reward['expires_at'] ?? $campaign->ends_at,'metadata'=>['label'=>$reward['label'] ?? null,'cta'=>$reward['cta'] ?? null,'reward_mode'=>$mode]]
        );
    }

    public function executeSystemDraw(PromotionCampaign $campaign, int $quantity): array
    {
        $this->compliance->assertSystemDrawAllowed($campaign);
        if ($campaign->status !== 'published' && $campaign->status !== 'ended') throw ValidationException::withMessages(['campaign'=>['A campanha precisa estar publicada ou encerrada para apuração.']]);

        return DB::transaction(function () use ($campaign, $quantity) {
            $existing = PromotionCampaignWinner::where('campaign_id', $campaign->id)->orderBy('position')->get();
            if ($existing->isNotEmpty()) return $existing->all();
            $eligible = PromotionCampaignParticipation::query()->where('campaign_id',$campaign->id)->where('status','valid')->pluck('user_id')->unique()->values()->all();
            if (count($eligible) < $quantity) throw ValidationException::withMessages(['quantity'=>['Não há participantes elegíveis suficientes.']]);
            $pool = array_values($eligible);
            $seedMaterial = $campaign->uuid.'|'.now()->toIso8601String().'|'.implode(',', $pool);
            $evidenceHash = hash('sha256', $seedMaterial);
            $winners = [];
            for ($position=1; $position <= $quantity; $position++) {
                $index = random_int(0, count($pool)-1);
                $userId = array_splice($pool, $index, 1)[0];
                $winner = PromotionCampaignWinner::create(['campaign_id'=>$campaign->id,'user_id'=>$userId,'position'=>$position,'method'=>'system_random','evidence_hash'=>$evidenceHash,'selected_at'=>now(),'metadata'=>['eligible_count'=>count($eligible)]]);
                $reward = $this->issueConfiguredReward($campaign, (int) $userId, ['winner','selected']);
                if ($reward) {
                    $winner->metadata = array_merge($winner->metadata ?: [], ['reward_id'=>$reward->id,'reward_kind'=>$reward->kind]);
                    $winner->save();
                }
                $winners[] = $winner->fresh();
            }
            return $winners;
        });
    }

    public function finalizeOrderReward(CommerceOrder $order): void
    {
        $rewardId = (int) data_get($order->metadata, 'campaign_reward_id', 0);
        if ($rewardId <= 0 || $order->status !== 'paid') return;

        DB::transaction(function () use ($order, $rewardId) {
            $reward = PromotionCampaignReward::query()->lockForUpdate()->find($rewardId);
            if (! $reward || (int) $reward->user_id !== (int) $order->user_id) return;
            if ($reward->status === 'redeemed') return;
            if ($reward->status !== 'reserved') return;
            if ((int) data_get($reward->metadata, 'reserved_order_id', 0) !== (int) $order->id) return;

            $metadata = is_array($reward->metadata) ? $reward->metadata : [];
            $metadata['redeemed_order_id'] = (int) $order->id;
            $metadata['redeemed_order_public_id'] = $order->public_id;
            $metadata['redeemed_at'] = now()->toIso8601String();
            unset($metadata['reserved_order_id'], $metadata['reserved_order_public_id'], $metadata['reserved_at']);
            $reward->status = 'redeemed';
            $reward->redeemed_at = now();
            $reward->metadata = $metadata;
            $reward->save();
        });
    }

    public function analytics(PromotionCampaign $campaign): array
    {
        $attributions = $campaign->attributions();
        $participants = $campaign->participations()->where('status','valid')->distinct('user_id')->count('user_id');
        $views = (clone $attributions)->where('touchpoint','view')->count();
        $purchases = (clone $attributions)->where('touchpoint','purchase')->distinct('order_id')->count('order_id');
        $gmv = (float) (clone $attributions)->sum('gmv');
        $platformRevenue = (float) (clone $attributions)->sum('platform_revenue');
        $producerCost = (float) (clone $attributions)->sum('producer_cost');
        return ['views'=>$views,'participants'=>$participants,'purchases'=>$purchases,'conversion_participant_to_purchase'=>$participants > 0 ? round(($purchases/$participants)*100,2) : 0,'gmv'=>$gmv,'platform_revenue'=>$platformRevenue,'producer_cost'=>$producerCost,'net_campaign_contribution'=>round($platformRevenue-$producerCost,2),'revenue_per_participant'=>$participants > 0 ? round($platformRevenue/$participants,2) : 0];
    }
}
