<?php

namespace App\Http\Middleware;

use App\Models\Establishment;
use App\Models\Organization;
use App\Models\Production;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function __construct(
        private readonly ApplicationContext $application,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->findTenant($request);
        if ($resolved) {
            if (! $this->belongsToApplication($resolved)) {
                return ApiResponse::error('TENANT_SCOPE_MISMATCH', 'O recurso não pertence ao contexto da aplicação.', 404, [], $request);
            }
            $this->tenant->set($resolved);
            $request->attributes->set('tenant_context', $this->tenant);
        }

        try {
            return $next($request);
        } finally {
            $this->tenant->clear();
        }
    }

    private function findTenant(Request $request): ?Model
    {
        foreach (['organization', 'establishment', 'production'] as $key) {
            $value = $request->route($key);
            if ($value instanceof Model) return $value;
        }
        return null;
    }

    private function belongsToApplication(Model $tenant): bool
    {
        if (! $this->application->has()) return true;
        $applicationId = $this->application->id();

        if ($tenant instanceof Establishment || $tenant instanceof Production) {
            if (isset($tenant->app_id) && (int) $tenant->app_id === $applicationId) return true;
            if (method_exists($tenant, 'applications')) return $tenant->applications()->whereKey($applicationId)->exists();
            return false;
        }

        if ($tenant instanceof Organization) {
            return $tenant->applications()->whereKey($applicationId)->exists();
        }

        return true;
    }
}
