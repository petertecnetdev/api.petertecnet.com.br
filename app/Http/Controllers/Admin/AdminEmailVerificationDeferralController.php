<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationDeferralService;
use App\Support\EmailVerificationDeferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminEmailVerificationDeferralController extends Controller
{
    public function state(User $user): JsonResponse
    {
        return response()->json([
            'email_verification' => EmailVerificationDeferrals::state($user),
        ]);
    }

    public function reset(User $user, EmailVerificationDeferralService $service): JsonResponse
    {
        $result = $service->reset($user);

        Log::notice('Admin reset email verification deferrals', [
            'admin_user_id' => Auth::id(),
            'target_user_id' => $user->id,
            'deferrals_used_before' => $result['before']['deferrals_used'] ?? null,
            'deferrals_used_after' => $result['after']['deferrals_used'] ?? null,
        ]);

        return response()->json([
            'message' => 'Adiamentos da confirmação de e-mail resetados com sucesso.',
            'email_verification' => $result['after'],
        ]);
    }
}
