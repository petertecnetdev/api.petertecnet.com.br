<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebVitalSample extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'session_id',
        'metric_name',
        'metric_value',
        'rating',
        'path',
        'device_class',
        'connection_type',
        'navigation_type',
        'occurred_at',
    ];

    protected $casts = [
        'metric_value' => 'float',
        'occurred_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
