<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveMemory extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','observation_id','memory_type','title','summary','content','tags','importance','confidence','reinforcement_count','active','last_reinforced_at','last_accessed_at','expires_at'];
    protected $casts = ['content'=>'array','tags'=>'array','importance'=>'float','confidence'=>'float','active'=>'boolean','last_reinforced_at'=>'datetime','last_accessed_at'=>'datetime','expires_at'=>'datetime'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function observation() { return $this->belongsTo(CognitiveObservation::class, 'observation_id'); }
    public function learningEvents() { return $this->hasMany(CognitiveLearningEvent::class, 'memory_id'); }
}
