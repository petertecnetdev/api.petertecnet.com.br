<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CognitiveExperiment extends Model
{
    use HasFactory;
    protected $fillable = ['code','name','dimension','hypothesis','protocol','pass_criteria','weight','enabled','metadata'];
    protected $casts = ['protocol'=>'array','pass_criteria'=>'array','weight'=>'float','enabled'=>'boolean','metadata'=>'array'];
    public function runs() { return $this->hasMany(CognitiveExperimentRun::class, 'experiment_id'); }
}
