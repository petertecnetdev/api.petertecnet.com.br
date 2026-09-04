<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
