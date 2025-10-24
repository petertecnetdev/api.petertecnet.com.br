<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployerSchedule extends Model
{
    use HasFactory;

    protected $table = 'employer_schedules';

    protected $fillable = [
        'employer_id',
        'day_of_week',
        'reserved_date',
        'start_time',
        'end_time',
        'is_active',
        'type',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reserved_date' => 'date',
    ];

    public function employer()
    {
        return $this->belongsTo(Employer::class, 'employer_id');
    }
}
