<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityGlobalSession extends Model
{
    protected $fillable = [
        'session_id', 'user_id', 'trusted_device_id', 'session_token_hash', 'refresh_token_hash',
        'previous_refresh_token_hash', 'previous_refresh_valid_until', 'auth_version',
        'device_label', 'ip_address', 'country_code', 'user_agent', 'accept_language',
        'risk_score', 'risk_reasons', 'last_seen_at', 'idle_expires_at', 'expires_at',
        'absolute_expires_at', 'refresh_expires_at', 'revoked_at', 'revoke_reason', 'metadata',
    ];

    protected $hidden = ['session_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash'];

    protected $casts = [
        'auth_version' => 'integer',
        'risk_score' => 'integer',
        'risk_reasons' => 'array',
        'previous_refresh_valid_until' => 'datetime',
        'last_seen_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trustedDevice()
    {
        return $this->belongsTo(IdentityTrustedDevice::class, 'trusted_device_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at?->isFuture()
            && $this->refresh_expires_at?->isFuture()
            && ($this->absolute_expires_at === null || $this->absolute_expires_at->isFuture())
            && ($this->idle_expires_at === null || $this->idle_expires_at->isFuture());
    }
}
