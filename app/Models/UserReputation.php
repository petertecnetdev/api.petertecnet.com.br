<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReputation extends Model
{
    protected $fillable = [
        'app_id','user_id','category','score','calibration_score','brier_score','confidence',
        'resolved_count','correct_count','average_lead_days','last_calculated_at',
    ];

    protected $casts = [
        'score'=>'float','calibration_score'=>'float','brier_score'=>'float','confidence'=>'float',
        'average_lead_days'=>'float','last_calculated_at'=>'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
