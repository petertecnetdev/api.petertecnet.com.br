<?php

namespace App\Http\Middleware;

use App\Models\ResourceRef;
use App\Services\ContextualAccessService;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;

class RequireContextualPermission
{
    public function __construct(
        private readonly ContextualAccessService $access,
        private readonly ApplicationContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $uuid = $request->header('X-Peter-Resource');
        $routeResource = $request->route('resourceRef') ?? $request->route('resource_ref');
        if ($routeResource instanceof ResourceRef) $uuid = $routeResource->uuid;
        elseif (is_string($routeResource) && $routeResource !== '') $uuid = $routeResource;

        if ($uuid) {
            $resource = ResourceRef::query()->active()
                ->where('uuid', $uuid)
                ->where('application_id', $this->context->id())
                ->firstOrFail();
            abort_unless($this->access->canForResource($user, $permission, $resource), 403, 'Você não possui permissão para esta ação neste recurso.');
        } else {
            abort_unless($this->access->hasPermission($user, $permission, $this->context->id()), 403, 'Você não possui permissão para esta ação.');
        }

        return $next($request);
    }
}
