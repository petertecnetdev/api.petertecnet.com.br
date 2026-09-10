<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'uuid', 'impersonator_user_id', 'impersonated_user_id', 'application_id', 'reason',
        'handoff_token_hash', 'handoff_expires_at', 'handoff_used_at', 'started_at', 'expires_at',
        'ended_at', 'ended_by_user_id', 'end_reason', 'ip_address', 'user_agent', 'metadata',
    ];

    protected $hidden = ['handoff_token_hash'];

    protected $casts = [
        'metadata' => 'array',
        'handoff_expires_at' => 'datetime',
        'handoff_used_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ImpersonationAuditLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at?->isFuture();
    }

    public function finish(?User $endedBy = null, string $reason = 'ended'): void
    {
        if ($this->ended_at) return;

        $this->forceFill([
            'ended_at' => now(),
            'ended_by_user_id' => $endedBy?->id,
            'end_reason' => $reason,
            'handoff_token_hash' => null,
            'handoff_expires_at' => null,
        ])->save();
    }
}
