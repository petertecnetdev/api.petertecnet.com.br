<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OnboardingSession extends Model
{
    protected $fillable = [
        'uuid',
        'actor_id',
        'subject_user_id',
        'app_id',
        'establishment_id',
        'context',
        'current_step',
        'status',
        'state',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'state' => 'array',
        'current_step' => 'integer',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid = $session->uuid ?: (string) Str::uuid();
            $session->last_activity_at = $session->last_activity_at ?: now();
            $session->expires_at = $session->expires_at ?: now()->addDays(14);
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subjectUser()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }
}
