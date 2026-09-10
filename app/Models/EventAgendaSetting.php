<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAgendaSetting extends Model
{
    protected $fillable = [
        'app_id',
        'production_id',
        'is_active',
        'generation_weeks',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'production_id' => 'integer',
        'is_active' => 'boolean',
        'generation_weeks' => 'integer',
    ];

    public function production()
    {
        return $this->belongsTo(Production::class);
    }
}
