<?php

namespace App\Domain\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IdentityTrustedDevice extends Model
{
    protected $fillable = [
        'device_id', 'user_id', 'secret_hash', 'name', 'platform', 'browser',
        'ip_address', 'country_code', 'user_agent', 'last_seen_at', 'trusted_at',
        'expires_at', 'revoked_at', 'revoke_reason', 'metadata',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'trusted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
