<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationDeferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class EmailVerificationDeferralController extends Controller
{
    public function state(EmailVerificationDeferralService $service): JsonResponse
    {
        return response()->json([
            'email_verification' => $service->stateForUserId((int) Auth::id()),
        ]);
    }

    public function defer(EmailVerificationDeferralService $service): JsonResponse
    {
        $result = $service->deferForUserId((int) Auth::id());

        return response()->json($result['body'], $result['status']);
    }
}
