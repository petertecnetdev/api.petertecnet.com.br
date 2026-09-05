<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApplicationAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $application = $request->attributes->get('application');

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Autenticação necessária.',
                'code' => 'AUTHENTICATION_REQUIRED',
            ], 401);
        }

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Contexto da aplicação não encontrado.',
                'code' => 'APPLICATION_CONTEXT_REQUIRED',
            ], 404);
        }

        $ownerEmail = strtolower(trim((string) env('PETER_TECNET_OWNER_EMAIL', 'petertecnet@gmail.com')));
        if ($ownerEmail !== '' && strtolower((string) $user->email) === $ownerEmail) {
            $request->attributes->set('application_admin_source', 'ecosystem_owner');
            return $next($request);
        }

        $membership = $user->applications()
            ->where('applications.id', $application->id)
            ->first();

        if (! $membership || $membership->pivot?->status !== 'active') {
            return $this->forbidden();
        }

        $roles = array_values(array_unique(array_filter([
            $membership->pivot?->role,
            ...$this->metadataRoles($membership->pivot?->metadata),
        ])));

        if (! in_array('application_admin', $roles, true)) {
            return $this->forbidden();
        }

        $request->attributes->set('application_admin_source', 'application_membership');
        $request->attributes->set('application_admin_membership', $membership->pivot);

        return $next($request);
    }

    private function metadataRoles(mixed $metadata): array
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        if (! is_array($metadata)) {
            return [];
        }

        $roles = $metadata['roles'] ?? [];
        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    private function forbidden(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Você não possui permissão administrativa nesta aplicação.',
            'code' => 'APPLICATION_ADMIN_REQUIRED',
        ], 403);
    }
}
