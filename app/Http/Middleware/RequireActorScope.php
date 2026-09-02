<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Services\ApplicationAccessService;
use App\Support\ActorContext;
use App\Support\ApplicationContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActorScope
{
    public function __construct(
        private readonly ApplicationAccessService $access,
        private readonly ApplicationContext $applicationContext,
        private readonly ActorContext $actorContext,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $this->actorContext->user() ?: $request->user();
        abort_unless($user, 401, 'Autenticação necessária.');

        $organizationId = null;
        if ($this->tenantContext->has() && $this->tenantContext->type() === 'Organization') {
            $organizationId = (int) $this->tenantContext->id();
        }

        abort_unless(
            $this->access->allows($user, $this->applicationContext->id(), $scope, $organizationId),
            403,
            'Você não possui o escopo necessário para esta operação.'
        );

        return $next($request);
    }
}
