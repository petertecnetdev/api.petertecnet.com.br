<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdentityAuthEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'identity_session_id', 'application_id', 'event_type', 'outcome',
        'ip_address', 'user_agent', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function session()
    {
        return $this->belongsTo(IdentitySession::class, 'identity_session_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
