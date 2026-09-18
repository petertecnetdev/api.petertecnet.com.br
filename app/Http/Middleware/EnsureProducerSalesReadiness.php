<?php

namespace App\Http\Middleware;

use App\Services\ProducerOnboardingService;
use Closure;
use Illuminate\Http\Request;

final class EnsureProducerSalesReadiness
{
    public function __construct(
        private readonly ProducerOnboardingService $onboarding,
    ) {}

    public function handle(Request $request, Closure $next, string $source = 'route')
    {
        $eventId = $source === 'checkout'
            ? (int) $request->input('event_id')
            : (int) $request->route('id');

        if ($eventId > 0) {
            $this->onboarding->assertCanSellForEvent($eventId);
        }

        return $next($request);
    }
}
