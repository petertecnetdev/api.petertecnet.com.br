<?php

namespace Tests\Feature;

use App\Models\PromotionCampaign;
use App\Services\PromotionCampaignComplianceService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PromotionCampaignComplianceTest extends TestCase
{
    public function test_objective_reward_for_all_is_not_forced_into_regulated_flow(): void
    {
        $service = app(PromotionCampaignComplianceService::class);
        $result = $service->classification(['type'=>'objective_reward','reward'=>['mode'=>'all_eligible','has_prize'=>false]]);
        $this->assertFalse($result['requires_authorization']);
        $this->assertSame('not_required',$result['compliance_status']);
    }

    public function test_draw_is_classified_for_compliance_review(): void
    {
        $service = app(PromotionCampaignComplianceService::class);
        $result = $service->classification(['type'=>'draw','reward'=>['mode'=>'winner','has_prize'=>true]]);
        $this->assertTrue($result['requires_authorization']);
        $this->assertSame('review',$result['compliance_status']);
    }

    public function test_regulated_campaign_cannot_publish_without_approved_authorization(): void
    {
        $this->expectException(ValidationException::class);
        $campaign = new PromotionCampaign(['requires_authorization'=>true,'compliance_status'=>'review']);
        app(PromotionCampaignComplianceService::class)->assertPublishable($campaign);
    }
}
