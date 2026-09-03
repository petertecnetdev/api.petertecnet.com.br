<?php

namespace App\Domain\Identity\Models;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityDevice extends Model
{
    protected $fillable = [
        'device_id', 'user_id', 'last_application_id', 'name', 'platform', 'browser',
        'user_agent', 'first_ip_address', 'last_ip_address', 'trusted', 'first_seen_at',
        'last_seen_at', 'trusted_at', 'metadata',
    ];

    protected $casts = [
        'trusted' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'trusted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lastApplication()
    {
        return $this->belongsTo(Application::class, 'last_application_id');
    }

    public function sessions()
    {
        return $this->hasMany(IdentitySession::class, 'device_id');
    }

    public function globalSessions()
    {
        return $this->hasMany(IdentityGlobalSession::class, 'device_id');
    }
}
