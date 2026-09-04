<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveBelief extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','subject','predicate','object_key','object_value','confidence','evidence_count','contradiction_count','status','source_summary','last_evidence_at'];
    protected $casts = ['object_value'=>'array','confidence'=>'float','last_evidence_at'=>'datetime'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function learningEvents() { return $this->hasMany(CognitiveLearningEvent::class, 'belief_id'); }
}
