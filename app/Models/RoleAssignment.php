<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignment extends Model
{
    protected $fillable = [
        'user_id', 'role_id', 'application_id', 'establishment_id', 'resource_type', 'resource_id',
        'context_key', 'status', 'starts_at', 'expires_at', 'metadata', 'assigned_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function contextKey(
        ?int $applicationId = null,
        ?int $establishmentId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
    ): string {
        if ($resourceType !== null && $resourceId !== null) {
            return sprintf(
                'app:%s:est:%s:resource:%s:%d',
                $applicationId ?? '*',
                $establishmentId ?? '*',
                strtolower(trim($resourceType)),
                $resourceId,
            );
        }

        if ($establishmentId !== null) {
            return sprintf('app:%s:est:%d', $applicationId ?? '*', $establishmentId);
        }

        if ($applicationId !== null) {
            return 'app:' . $applicationId;
        }

        return 'global';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
