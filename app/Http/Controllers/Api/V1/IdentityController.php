<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\ApplicationAccessService;
use App\Http\Controllers\AuthController as LegacyAuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleAuthRequest;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

/**
 * Stable v1 Identity facade. Legacy authentication mechanics remain behind
 * this adapter until their storage implementation can be retired, while all
 * application membership/authorization is written to the canonical access model.
 */
final class IdentityController extends Controller
{
    public function __construct(
        private readonly LegacyAuthController $identity,
        private readonly ApplicationContext $applicationContext,
        private readonly ApplicationAccessService $access,
    ) {}

    public function login(Request $request)
    {
        $response = $this->identity->login($request);
        $this->grantCurrentApplication(auth()->user());
        return $response;
    }

    public function register(Request $request)
    {
        if ($this->applicationContext->has()) {
            $request->merge(['app_id' => $this->applicationContext->id()]);
        }

        $response = $this->identity->register($request);

        if ($response->getStatusCode() < 300 && $this->applicationContext->has()) {
            $user = User::query()->where('email', strtolower(trim((string) $request->input('email'))))->first();
            $this->grantCurrentApplication($user);
        }

        return $response;
    }

    public function google(GoogleAuthRequest $request)
    {
        $response = $this->identity->googleAuth($request);
        $this->grantCurrentApplication(auth()->user() ?: auth('api')->user());
        return $response;
    }

    public function refresh(Request $request) { return $this->identity->refresh($request); }
    public function logout(Request $request) { return $this->identity->logout($request); }
    public function me(Request $request) { return $this->identity->me($request); }
    public function check(Request $request) { return $this->identity->checkauth($request); }
    public function verifyEmail(Request $request) { return $this->identity->emailVerify($request); }
    public function resendVerification(Request $request) { return $this->identity->resendCodeEmailVerification(); }
    public function changePassword(Request $request) { return $this->identity->changePassword($request); }
    public function requestPasswordReset(Request $request) { return $this->identity->sendResetCodeEmail($request); }
    public function resetPassword(Request $request) { return $this->identity->resetPassword($request); }

    private function grantCurrentApplication(?User $user): void
    {
        if (! $user || ! $this->applicationContext->has()) {
            return;
        }

        $applicationId = $this->applicationContext->id();
        $user->applications()->syncWithoutDetaching([
            $applicationId => [
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);

        $this->access->grantUser(
            $applicationId,
            (int) $user->id,
            'member',
            config('platform.role_scopes.member', ['profile.read'])
        );
    }
}
