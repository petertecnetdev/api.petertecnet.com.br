<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PromotionCampaign extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid','app_id','app_slug','production_id','event_id','created_by','type','objective','status','title','description',
        'visibility','starts_at','ends_at','configuration','reward','requires_authorization','compliance_status',
        'authorization_number','authorization_metadata','sponsor_metadata','boost_metadata','published_at','ended_at',
    ];

    protected $casts = [
        'starts_at'=>'datetime','ends_at'=>'datetime','published_at'=>'datetime','ended_at'=>'datetime',
        'configuration'=>'array','reward'=>'array','authorization_metadata'=>'array','sponsor_metadata'=>'array','boost_metadata'=>'array',
        'requires_authorization'=>'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $campaign) {
            $campaign->uuid ??= (string) Str::uuid();
        });
    }

    public function event(){ return $this->belongsTo(Event::class); }
    public function production(){ return $this->belongsTo(Production::class); }
    public function creator(){ return $this->belongsTo(User::class, 'created_by'); }
    public function options(){ return $this->hasMany(PromotionCampaignOption::class, 'campaign_id')->orderBy('sort_order'); }
    public function participations(){ return $this->hasMany(PromotionCampaignParticipation::class, 'campaign_id'); }
    public function rewards(){ return $this->hasMany(PromotionCampaignReward::class, 'campaign_id'); }
    public function winners(){ return $this->hasMany(PromotionCampaignWinner::class, 'campaign_id')->orderBy('position'); }
    public function attributions(){ return $this->hasMany(PromotionCampaignAttribution::class, 'campaign_id'); }

    public function isActive(): bool
    {
        $now = now();
        return $this->status === 'published'
            && (! $this->starts_at || $this->starts_at->lte($now))
            && (! $this->ends_at || $this->ends_at->gte($now));
    }
}
