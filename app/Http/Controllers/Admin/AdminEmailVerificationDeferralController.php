<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailVerificationDeferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminEmailVerificationDeferralController extends Controller
{
    public function state(User $user): JsonResponse
    {
        return response()->json([
            'email_verification' => EmailVerificationDeferrals::state($user),
        ]);
    }

    public function reset(User $user): JsonResponse
    {
        $before = null;

        $after = DB::transaction(function () use ($user, &$before): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = EmailVerificationDeferrals::state($lockedUser);

            return EmailVerificationDeferrals::reset($lockedUser);
        });

        Log::notice('Admin reset email verification deferrals', [
            'admin_user_id' => Auth::id(),
            'target_user_id' => $user->id,
            'deferrals_used_before' => $before['deferrals_used'] ?? null,
            'deferrals_used_after' => $after['deferrals_used'] ?? null,
        ]);

        return response()->json([
            'message' => 'Adiamentos da confirmação de e-mail resetados com sucesso.',
            'email_verification' => $after,
        ]);
    }
}
