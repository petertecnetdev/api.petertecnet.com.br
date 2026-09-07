<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromotionCampaignParticipation extends Model {
    protected $fillable=['campaign_id','user_id','option_id','action','status','source','referral_code','idempotency_key','metadata'];
    protected $casts=['metadata'=>'array'];
    public function campaign(){return $this->belongsTo(PromotionCampaign::class,'campaign_id');}
    public function user(){return $this->belongsTo(User::class);}
    public function option(){return $this->belongsTo(PromotionCampaignOption::class,'option_id');}
}
