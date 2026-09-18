<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Services\ProducerOnboardingService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class ProducerOnboardingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProducerOnboardingService $onboarding,
    ) {}

    public function show(Request $request, int $organizationId)
    {
        $production = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($organizationId);

        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');

        abort_unless($user && ($admin || (int) $production->user_id === (int) $user->id), 403);

        return response()->json([
            'onboarding' => $this->onboarding->status($production),
        ]);
    }
}
