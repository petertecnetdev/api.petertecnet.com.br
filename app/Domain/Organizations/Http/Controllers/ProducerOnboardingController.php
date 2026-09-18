<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ProducerOnboardingService;
use Illuminate\Http\Request;

final class ProducerOnboardingController extends Controller
{
    public function __construct(
        private readonly ProducerOnboardingService $onboarding,
    ) {}

    public function show(Request $request, int $organizationId)
    {
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');

        return response()->json([
            'onboarding' => $this->onboarding->statusForUser(
                $organizationId,
                $user,
                $admin,
            ),
        ]);
    }
}
