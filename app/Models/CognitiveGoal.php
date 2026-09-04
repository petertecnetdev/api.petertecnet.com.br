<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveGoal extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','user_id','origin','title','description','priority','status','success_criteria','context','progress','started_at','completed_at'];
    protected $casts = ['success_criteria'=>'array','context'=>'array','progress'=>'float','started_at'=>'datetime','completed_at'=>'datetime'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
