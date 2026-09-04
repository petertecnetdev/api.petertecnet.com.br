<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveLearningEvent extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','observation_id','memory_id','belief_id','created_by','event_type','reason','before_state','after_state','confidence_delta','metadata'];
    protected $casts = ['before_state'=>'array','after_state'=>'array','confidence_delta'=>'float','metadata'=>'array'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function observation() { return $this->belongsTo(CognitiveObservation::class, 'observation_id'); }
    public function memory() { return $this->belongsTo(CognitiveMemory::class, 'memory_id'); }
    public function belief() { return $this->belongsTo(CognitiveBelief::class, 'belief_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
