<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveStateSnapshot extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','attention','self_model','world_model','working_memory','uncertainties','active_goals','metrics','captured_at'];
    protected $casts = ['attention'=>'array','self_model'=>'array','world_model'=>'array','working_memory'=>'array','uncertainties'=>'array','active_goals'=>'array','metrics'=>'array','captured_at'=>'datetime'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
}
