<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PromotionCampaignOption extends Model {
    protected $fillable=['campaign_id','label','description','image','sort_order','votes_count','metadata'];
    protected $casts=['metadata'=>'array','votes_count'=>'integer','sort_order'=>'integer'];
    public function campaign(){return $this->belongsTo(PromotionCampaign::class,'campaign_id');}
}
