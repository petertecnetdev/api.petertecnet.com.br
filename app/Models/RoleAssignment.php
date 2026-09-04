<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RoleAssignment extends Model
{
    protected $fillable = [
        'user_id', 'role_id', 'application_id', 'establishment_id', 'resource_ref_id', 'resource_type', 'resource_id',
        'context_key', 'status', 'starts_at', 'expires_at', 'metadata', 'assigned_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (RoleAssignment $assignment) {
            $scoped = $assignment->establishment_id || $assignment->resource_ref_id || $assignment->resource_type || $assignment->resource_id;
            if ($scoped && ! $assignment->application_id) {
                throw new LogicException('Assignments de estabelecimento ou recurso exigem application_id.');
            }

            if (($assignment->resource_type && ! $assignment->resource_id) || (! $assignment->resource_type && $assignment->resource_id)) {
                throw new LogicException('resource_type e resource_id devem ser informados em conjunto.');
            }

            if ($assignment->resource_ref_id) {
                $resource = ResourceRef::query()->find($assignment->resource_ref_id);
                if (! $resource || (int) $resource->application_id !== (int) $assignment->application_id) {
                    throw new LogicException('O recurso registrado não pertence ao application_id do assignment.');
                }
                if ($assignment->establishment_id && $resource->establishment_id && (int) $resource->establishment_id !== (int) $assignment->establishment_id) {
                    throw new LogicException('O recurso registrado não pertence ao estabelecimento do assignment.');
                }
            }
        });
    }

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
        ?string $resourceUuid = null,
    ): string {
        if ($resourceUuid) return 'resource:' . strtolower(trim($resourceUuid));

        if ($resourceType !== null && $resourceId !== null) {
            return sprintf('app:%d:est:%s:resource:%s:%d', $applicationId, $establishmentId ?? '*', strtolower(trim($resourceType)), $resourceId);
        }
        if ($establishmentId !== null) return sprintf('app:%d:est:%d', $applicationId, $establishmentId);
        if ($applicationId !== null) return 'app:' . $applicationId;
        return 'global';
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function establishment(): BelongsTo { return $this->belongsTo(Establishment::class); }
    public function resourceRef(): BelongsTo { return $this->belongsTo(ResourceRef::class); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
