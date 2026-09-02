<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\AuthController as LegacyAuthController;
use App\Http\Controllers\Controller;
use App\Http\Requests\GoogleAuthRequest;
use Illuminate\Http\Request;

/**
 * Stable Identity HTTP contract.
 *
 * During the compatibility window the implementation delegates to the proven
 * authentication controller. Product routes consume this class, so the legacy
 * controller can later be replaced internally without changing /api/v1.
 */
final class IdentityController extends Controller
{
    public function __construct(private readonly LegacyAuthController $identity) {}

    public function login(Request $request)
    {
        return $this->identity->login($request);
    }

    public function register(Request $request)
    {
        return $this->identity->register($request);
    }

    public function google(GoogleAuthRequest $request)
    {
        return $this->identity->googleAuth($request);
    }

    public function refresh(Request $request)
    {
        return $this->identity->refresh($request);
    }

    public function logout(Request $request)
    {
        return $this->identity->logout($request);
    }

    public function me(Request $request)
    {
        return $this->identity->me($request);
    }

    public function check(Request $request)
    {
        return $this->identity->checkauth($request);
    }

    public function verifyEmail(Request $request)
    {
        return $this->identity->emailVerify($request);
    }

    public function resendVerification(Request $request)
    {
        return $this->identity->resendCodeEmailVerification();
    }

    public function changePassword(Request $request)
    {
        return $this->identity->changePassword($request);
    }

    public function requestPasswordReset(Request $request)
    {
        return $this->identity->sendResetCodeEmail($request);
    }

    public function resetPassword(Request $request)
    {
        return $this->identity->resetPassword($request);
    }
}
