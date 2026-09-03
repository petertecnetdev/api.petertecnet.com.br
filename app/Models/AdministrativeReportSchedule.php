<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrativeReportSchedule extends Model
{
    protected $fillable = [
        'user_id', 'name', 'report_key', 'format', 'cadence', 'day_of_week', 'day_of_month', 'hour', 'minute', 'timezone',
        'filters', 'is_active', 'last_run_at', 'next_run_at',
    ];

    protected $casts = ['filters' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
