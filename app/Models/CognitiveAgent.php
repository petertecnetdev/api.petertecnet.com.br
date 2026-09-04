<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveAgent extends Model
{
    use HasFactory;

    protected $fillable = ['slug','name','description','identity','purpose','capabilities','constraints','values','self_model','status','learning_enabled','metadata','last_active_at'];
    protected $casts = ['identity'=>'array','capabilities'=>'array','constraints'=>'array','values'=>'array','self_model'=>'array','metadata'=>'array','learning_enabled'=>'boolean','last_active_at'=>'datetime'];

    public function observations() { return $this->hasMany(CognitiveObservation::class, 'agent_id'); }
    public function memories() { return $this->hasMany(CognitiveMemory::class, 'agent_id'); }
    public function beliefs() { return $this->hasMany(CognitiveBelief::class, 'agent_id'); }
    public function goals() { return $this->hasMany(CognitiveGoal::class, 'agent_id'); }
    public function stateSnapshots() { return $this->hasMany(CognitiveStateSnapshot::class, 'agent_id'); }
    public function experimentRuns() { return $this->hasMany(CognitiveExperimentRun::class, 'agent_id'); }
    public function learningEvents() { return $this->hasMany(CognitiveLearningEvent::class, 'agent_id'); }
}
