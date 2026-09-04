<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventLifecycleAction extends Model
{
    protected $fillable = [
        'app_id',
        'event_id',
        'actor_user_id',
        'action',
        'from_status',
        'to_status',
        'reason',
        'previous_start_date',
        'previous_end_date',
        'new_start_date',
        'new_end_date',
        'metadata',
    ];

    protected $casts = [
        'app_id' => 'integer',
        'event_id' => 'integer',
        'actor_user_id' => 'integer',
        'previous_start_date' => 'datetime',
        'previous_end_date' => 'datetime',
        'new_start_date' => 'datetime',
        'new_end_date' => 'datetime',
        'metadata' => 'array',
    ];

    public function event(){ return $this->belongsTo(Event::class); }
    public function actor(){ return $this->belongsTo(User::class, 'actor_user_id'); }
    public function application(){ return $this->belongsTo(Application::class, 'app_id'); }
}
