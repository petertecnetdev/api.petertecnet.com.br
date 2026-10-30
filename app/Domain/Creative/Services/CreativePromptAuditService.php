<?php

namespace App\Domain\Creative\Services;

use App\Domain\Creative\Models\CreativePromptTemplate;
use App\Models\EcosystemAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CreativePromptAuditService
{
    public function record(Request $request, string $action, ?CreativePromptTemplate $record, array $before, array $after): void
    {
        EcosystemAuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => CreativePromptTemplate::class,
            'entity_id' => $record?->id,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
