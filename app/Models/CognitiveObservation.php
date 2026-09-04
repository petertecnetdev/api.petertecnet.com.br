<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveObservation extends Model
{
    use HasFactory;
    protected $fillable = ['agent_id','user_id','application_id','interaction_id','source_channel','event_type','entity_type','entity_id','external_key','payload','salience','processed_at'];
    protected $casts = ['payload'=>'array','salience'=>'float','processed_at'=>'datetime'];
    public function agent() { return $this->belongsTo(CognitiveAgent::class, 'agent_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function application() { return $this->belongsTo(Application::class); }
    public function interaction() { return $this->belongsTo(Interaction::class); }
    public function memories() { return $this->hasMany(CognitiveMemory::class, 'observation_id'); }
    public function learningEvents() { return $this->hasMany(CognitiveLearningEvent::class, 'observation_id'); }
}
