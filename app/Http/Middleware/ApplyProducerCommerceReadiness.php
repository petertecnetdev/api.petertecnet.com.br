<?php

namespace App\Http\Middleware;

use App\Services\ProducerOnboardingService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplyProducerCommerceReadiness
{
    public function __construct(
        private readonly ProducerOnboardingService $onboarding,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }

        $slug = (string) $request->route('slug');
        if ($slug === '') {
            return $response;
        }

        $status = $this->onboarding->statusForEventSlug($slug);
        if (! $status['assisted_onboarding']) {
            return $response;
        }

        $data = $response->getData(true);
        $payment = (array) ($data['payment_config'] ?? []);
        $payment['onboarding_status'] = $status['status'];
        $payment['can_sell_tickets'] = $status['can_sell_tickets'];
        $payment['next_onboarding_step'] = $status['next_step'];

        if (! $status['can_sell_tickets']) {
            $payment['available'] = false;
            $payment['methods'] = [];
            $payment['message'] = data_get(
                $status,
                'next_step.detail',
                'Conclua a configuração comercial da produção.'
            );
        }

        $data['payment_config'] = $payment;
        $response->setData($data);

        return $response;
    }
}
