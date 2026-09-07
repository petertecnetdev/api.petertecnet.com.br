<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromotionCampaignWinner extends Model {
    protected $fillable=['campaign_id','user_id','option_id','position','method','evidence_hash','selected_at','metadata'];
    protected $casts=['selected_at'=>'datetime','metadata'=>'array','position'=>'integer'];
    public function campaign(){return $this->belongsTo(PromotionCampaign::class,'campaign_id');}
    public function user(){return $this->belongsTo(User::class);}
}
