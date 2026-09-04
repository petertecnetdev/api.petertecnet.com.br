<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResourceRelationship extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'application_id', 'resource_ref_id', 'relationship_type', 'resource_type', 'resource_id',
        'relationship_key', 'status', 'starts_at', 'ends_at', 'metadata', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ResourceRelationship $relationship) {
            if (! $relationship->application_id) {
                throw new LogicException('Relacionamentos de recurso exigem application_id.');
            }
            if (! $relationship->resource_type || ! $relationship->resource_id) {
                throw new LogicException('Relacionamentos exigem resource_type e resource_id.');
            }
            if ($relationship->resource_ref_id) {
                $resource = ResourceRef::query()->find($relationship->resource_ref_id);
                if (! $resource || (int) $resource->application_id !== (int) $relationship->application_id) {
                    throw new LogicException('O recurso registrado não pertence ao application_id do relacionamento.');
                }
                if ($resource->resource_type !== strtolower(trim($relationship->resource_type)) || (int) $resource->resource_id !== (int) $relationship->resource_id) {
                    throw new LogicException('Tipo/ID do relacionamento divergem do recurso registrado.');
                }
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public static function relationshipKey(
        string $subjectType,
        int $subjectId,
        string $relationshipType,
        string $resourceType,
        int $resourceId,
        ?int $applicationId = null,
        ?string $resourceUuid = null,
    ): string {
        $resourceNamespace = $resourceUuid
            ? 'uuid:' . strtolower(trim($resourceUuid))
            : sprintf('app:%d:%s:%d', $applicationId, strtolower(trim($resourceType)), $resourceId);

        return hash('sha256', implode('|', [
            strtolower(trim($subjectType)),
            $subjectId,
            strtolower(trim($relationshipType)),
            $resourceNamespace,
        ]));
    }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function resourceRef(): BelongsTo { return $this->belongsTo(ResourceRef::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
