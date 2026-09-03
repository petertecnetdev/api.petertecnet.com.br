<?php

namespace App\Domain\Identity\Models;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentitySession extends Model
{
    protected $fillable = [
        'session_id', 'user_id', 'app_id', 'auth_method', 'device_label', 'nickname',
        'ip_address', 'user_agent', 'risk_score', 'risk_reasons', 'trusted_device_id',
        'last_seen_at', 'idle_expires_at', 'expires_at', 'absolute_expires_at',
        'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'risk_reasons' => 'array',
        'last_seen_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function trustedDevice()
    {
        return $this->belongsTo(IdentityTrustedDevice::class, 'trusted_device_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->absolute_expires_at === null || $this->absolute_expires_at->isFuture())
            && ($this->idle_expires_at === null || $this->idle_expires_at->isFuture());
    }
}
