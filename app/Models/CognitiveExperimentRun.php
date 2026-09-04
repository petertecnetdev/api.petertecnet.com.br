<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveExperimentRun extends Model
{
    use HasFactory;
    protected $fillable = ['experiment_id','agent_id','user_id','input','evidence','metrics','score','result','notes','started_at','finished_at'];
    protected $casts = ['input'=>'array','evidence'=>'array','metrics'=>'array','score'=>'float','started_at'=>'datetime','finished_at'=>'datetime'];
    public function experiment() { return $this->belongsTo(CognitiveExperiment::class, 'experiment_id'); }
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
