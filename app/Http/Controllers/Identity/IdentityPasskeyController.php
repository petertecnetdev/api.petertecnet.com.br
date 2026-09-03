<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityCredential;
use App\Domain\Identity\Services\IdentityApplicationResolver;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\PasskeyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityPasskeyController extends Controller
{
    public function __construct(
        private readonly PasskeyService $passkeys,
        private readonly IdentityApplicationResolver $applications,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function registrationOptions(Request $request): JsonResponse
    {
        $application = $this->applications->resolve($request);
        return response()->json([
            'success' => true,
            'publicKey' => $this->passkeys->registrationOptions($request->user('api'), $request, $application),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $credential = $this->passkeys->register($user, $request);
        $application = $this->applications->resolve($request) ?: $this->sessions->current()?->application;

        $this->audit->record('passkey_registered', $user, $request, $application, [
            'credential_id' => $credential->id,
            'name' => $credential->name,
        ], true);

        return response()->json([
            'success' => true,
            'message' => 'Passkey cadastrada com sucesso.',
            'credential' => [
                'id' => $credential->id,
                'name' => $credential->name,
                'transports' => $credential->transports ?: [],
                'created_at' => $credential->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function authenticationOptions(Request $request): JsonResponse
    {
        $application = $this->applications->resolve($request);
        return response()->json([
            'success' => true,
            'publicKey' => $this->passkeys->authenticationOptions($request, $application),
        ]);
    }

    public function authenticate(Request $request): JsonResponse
    {
        $user = $this->passkeys->authenticate($request);
        $application = $this->applications->resolve($request);
        $issued = $this->sessions->issue($user, $request, 'passkey', $application);

        $this->audit->record('new_session', $user, $request, $application, [
            'device' => $issued['session']['device'],
            'session_id' => $issued['session']['id'],
            'auth_method' => 'passkey',
        ]);

        return response()->json(array_merge([
            'success' => true,
            'user' => $user,
            'application' => $application?->only(['id', 'name', 'slug', 'url']),
        ], $issued));
    }

    public function destroy(Request $request, int $credentialId): JsonResponse
    {
        $credential = IdentityCredential::query()->findOrFail($credentialId);
        $name = $credential->name;
        $this->passkeys->delete($request->user('api'), $credential);
        $this->audit->record('passkey_removed', $request->user('api'), $request, $this->sessions->current()?->application, [
            'credential_id' => $credentialId,
            'name' => $name,
        ]);

        return response()->json(['success' => true, 'message' => 'Passkey removida.']);
    }
}
