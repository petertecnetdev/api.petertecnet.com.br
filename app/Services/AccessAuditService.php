<?php

namespace App\Services;

use App\Models\EcosystemAuditLog;
use Illuminate\Http\Request;

class AccessAuditService
{
    public function record(
        Request $request,
        string $action,
        string $entityType,
        ?int $entityId,
        mixed $before = null,
        mixed $after = null,
        ?int $applicationId = null,
        ?int $establishmentId = null,
        array $metadata = [],
    ): EcosystemAuditLog {
        return EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id'),
            'application_id' => $applicationId,
            'establishment_id' => $establishmentId,
            'metadata' => $metadata,
        ]);
    }
}
