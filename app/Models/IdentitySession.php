<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdentitySession extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'device_id', 'last_application_id', 'auth_version',
        'session_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash',
        'previous_refresh_valid_until', 'last_seen_at', 'expires_at', 'refresh_expires_at',
        'revoked_at', 'revoke_reason', 'metadata',
    ];

    protected $hidden = [
        'session_token_hash', 'refresh_token_hash', 'previous_refresh_token_hash',
    ];

    protected $casts = [
        'auth_version' => 'integer',
        'previous_refresh_valid_until' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(IdentityDevice::class, 'device_id');
    }

    public function lastApplication()
    {
        return $this->belongsTo(Application::class, 'last_application_id');
    }

    public function active(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at?->isFuture()
            && $this->refresh_expires_at?->isFuture();
    }
}
