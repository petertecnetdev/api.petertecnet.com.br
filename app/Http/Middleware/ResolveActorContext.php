<?php

namespace App\Http\Middleware;

use App\Support\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActorContext
{
    public function __construct(private readonly ActorContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->setUser($request->user());
        $this->context->setClient($request->attributes->get('api_credential'));
        $request->attributes->set('actor_context', $this->context);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
