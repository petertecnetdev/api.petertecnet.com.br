<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromotionCampaignAttribution extends Model {
    protected $fillable=['campaign_id','user_id','order_id','touchpoint','gmv','platform_revenue','producer_cost','currency','idempotency_key','metadata'];
    protected $casts=['metadata'=>'array','gmv'=>'decimal:2','platform_revenue'=>'decimal:2','producer_cost'=>'decimal:2'];
    public function campaign(){return $this->belongsTo(PromotionCampaign::class,'campaign_id');}
}
