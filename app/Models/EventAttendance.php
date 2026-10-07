<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAttendance extends Model
{
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_GOING = 'going';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_INTERESTED,
        self::STATUS_GOING,
        self::STATUS_ATTENDED,
    ];

    protected $fillable = [
        'app_id',
        'event_id',
        'user_id',
        'status',
        'checked_in_at',
        'checkin_method',
        'checked_in_by',
        'distance_meters',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'distance_meters' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }
}
