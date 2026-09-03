<?php

namespace App\Http\Middleware;

use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApplicationCapability
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        if (! $this->context->has()) {
            return response()->json([
                'success' => false,
                'message' => 'O contexto da aplicação precisa ser resolvido antes da capacidade.',
                'code' => 'APPLICATION_CONTEXT_REQUIRED',
                'request_id' => $request->attributes->get('request_id'),
            ], 500);
        }

        $capabilities = array_values(array_unique(array_filter(array_map('trim', $capabilities))));
        foreach ($capabilities as $capability) {
            if (! $this->context->supports($capability)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta capacidade não está habilitada para a aplicação atual.',
                    'code' => 'CAPABILITY_NOT_AVAILABLE',
                    'capability' => $capability,
                    'application' => $this->context->slug(),
                    'request_id' => $request->attributes->get('request_id'),
                ], 404);
            }
        }

        return $next($request);
    }
}
