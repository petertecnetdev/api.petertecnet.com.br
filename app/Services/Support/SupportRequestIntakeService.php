<?php

namespace App\Services\Support;

use App\Models\SupportRequest;
use App\Services\ApplicationContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SupportRequestIntakeService
{
    public const SAFE_METADATA_KEYS = [
        'request_id', 'session_id', 'screen', 'component', 'action',
        'entity_type', 'entity_id', 'order_id', 'payment_id', 'checkout_id',
        'establishment_id', 'event_id', 'ticket_id', 'experiment', 'variant',
    ];

    private const FINANCIAL_METADATA_KEYS = ['order_id', 'payment_id', 'checkout_id'];

    public function create(Request $request, array $data, ApplicationContextService $context): SupportRequest
    {
        $application = $context->resolveSource($request, $data['metadata'] ?? [])
            ?: $context->resolve($request, null, $data['metadata'] ?? []);
        abort_unless($application, 422, 'Application context is required.');

        try { $user = Auth::guard('api')->user(); } catch (\Throwable) { $user = null; }

        $metadata = Arr::only($data['metadata'] ?? [], self::SAFE_METADATA_KEYS);
        $correlationId = $data['correlation_id'] ?? (string) Str::uuid();

        return SupportRequest::create([
            'application_id' => $application->id,
            'user_id' => $user?->id,
            'category' => $data['category'],
            'priority' => $this->priorityFor($data['category'], $data['priority'] ?? null, $metadata),
            'status' => 'open',
            'subject' => $data['subject'] ?? null,
            'description' => $data['description'],
            'route' => $data['route'] ?? $request->headers->get('X-Page-Route') ?? $request->headers->get('Referer'),
            'app_version' => $data['app_version'] ?? $request->header('X-App-Version'),
            'device' => substr((string) $request->userAgent(), 0, 255),
            'correlation_id' => $correlationId,
            'metadata' => array_filter(array_merge($metadata, [
                'request_id' => $request->header('X-Request-Id') ?: ($metadata['request_id'] ?? null),
                'source' => 'in_app_support',
            ])),
        ]);
    }

    public function priorityFor(string $category, ?string $requestedPriority, array $metadata = []): string
    {
        if ($requestedPriority === 'critical') return 'critical';
        if (in_array($category, ['payment', 'payout'], true) || $this->hasFinancialContext($metadata)) return 'high';
        return $requestedPriority ?? 'normal';
    }

    private function hasFinancialContext(array $metadata): bool
    {
        foreach (self::FINANCIAL_METADATA_KEYS as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') return true;
        }
        return false;
    }
}
