<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Rules\IdentityPassword;
use App\Domain\Identity\Services\CompromisedPasswordService;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IdentityPasswordController extends Controller
{
    public function __construct(
        private readonly CompromisedPasswordService $compromisedPasswords,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', new IdentityPassword($this->compromisedPasswords)],
            'password_confirmation' => ['required', 'same:password'],
        ]);
        $user = $request->user('api');

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $applicationSessions = $this->sessions->revokeAll($user, 'password_changed');
        $globalSessions = $this->globalSessions->revokeAll($user, 'password_changed');
        $this->audit->record('password_changed', $user, $request, null, [
            'application_sessions' => $applicationSessions,
            'global_sessions' => $globalSessions,
        ], true);

        try { auth('api')->logout(); } catch (\Throwable) {}

        $response = response()->json([
            'success' => true,
            'message' => 'Senha alterada. Entre novamente para continuar.',
        ]);
        foreach ($this->globalSessions->forgetCookies() as $cookie) $response->withCookie($cookie);
        return $response;
    }
}
