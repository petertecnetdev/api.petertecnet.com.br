<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Support\ApiResponse;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiProject
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = trim((string) ($request->bearerToken() ?: $request->header('X-API-Key')));
        if ($raw === '') {
            return ApiResponse::error('API_KEY_REQUIRED', 'Informe uma credencial de projeto válida.', 401, [], $request);
        }

        $prefix = substr($raw, 0, 16);
        $credential = ApiCredential::query()
            ->where('key_prefix', $prefix)
            ->with('project.application')
            ->get()
            ->first(fn (ApiCredential $candidate) => hash_equals($candidate->secret_hash, hash('sha256', $raw)));

        if (! $credential || ! $credential->valid() || ! $credential->project || $credential->project->status !== 'active') {
            return ApiResponse::error('API_KEY_INVALID', 'A credencial informada é inválida, expirada ou revogada.', 401, [], $request);
        }

        if ($this->applicationContext->has() && (int) $credential->project->application_id !== $this->applicationContext->id()) {
            return ApiResponse::error('APPLICATION_SCOPE_MISMATCH', 'A credencial não pertence à aplicação solicitada.', 403, [], $request);
        }

        $credential->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_credential', $credential);
        $request->attributes->set('api_project', $credential->project);
        $request->attributes->set('api_environment', $credential->project->environment);

        return $next($request);
    }
}
