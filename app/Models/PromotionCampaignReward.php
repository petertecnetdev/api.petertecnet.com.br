<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromotionCampaignReward extends Model {
    protected $fillable=['campaign_id','user_id','kind','code','value','status','expires_at','redeemed_at','metadata'];
    protected $casts=['expires_at'=>'datetime','redeemed_at'=>'datetime','metadata'=>'array','value'=>'decimal:2'];
    public function campaign(){return $this->belongsTo(PromotionCampaign::class,'campaign_id');}
    public function user(){return $this->belongsTo(User::class);}
}
