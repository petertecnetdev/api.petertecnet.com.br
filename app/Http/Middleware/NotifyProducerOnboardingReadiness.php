<?php

namespace App\Http\Middleware;

use App\Services\ProducerOnboardingService;
use Closure;
use Illuminate\Http\Request;

final class NotifyProducerOnboardingReadiness
{
    public function __construct(
        private readonly ProducerOnboardingService $onboarding,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $organizationId = (int) $request->route('organizationId');

            if ($organizationId > 0) {
                $this->onboarding->notifyIfSalesReadyForOrganizationId($organizationId);
            }
        }

        return $response;
    }
}
