<?php

namespace App\Http\Controllers;

use App\Models\SupportRequest;
use App\Services\ApplicationContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SupportRequestController extends Controller
{
    private const SAFE_METADATA_KEYS = [
        'request_id',
        'session_id',
        'screen',
        'component',
        'action',
        'entity_type',
        'entity_id',
        'order_id',
        'payment_id',
        'checkout_id',
        'establishment_id',
        'event_id',
        'ticket_id',
        'experiment',
        'variant',
    ];

    private const FINANCIAL_METADATA_KEYS = [
        'order_id',
        'payment_id',
        'checkout_id',
    ];

    public function store(Request $request, ApplicationContextService $context): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:payment,payout,account,bug,feature,usability,general'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,critical'],
            'subject' => ['nullable', 'string', 'max:180'],
            'description' => ['required', 'string', 'min:3', 'max:5000'],
            'route' => ['nullable', 'string', 'max:1000'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        $application = $context->resolveSource($request, $data['metadata'] ?? []) ?: $context->resolve($request, null, $data['metadata'] ?? []);
        abort_unless($application, 422, 'Application context is required.');

        try { $user = Auth::guard('api')->user(); } catch (\Throwable) { $user = null; }
        $correlationId = $data['correlation_id'] ?? (string) Str::uuid();
        $metadata = Arr::only($data['metadata'] ?? [], self::SAFE_METADATA_KEYS);
        $priority = $this->priorityFor($data['category'], $data['priority'] ?? null, $metadata);

        $support = SupportRequest::create([
            'application_id' => $application->id,
            'user_id' => $user?->id,
            'category' => $data['category'],
            'priority' => $priority,
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

        return response()->json(['data' => $support, 'correlation_id' => $correlationId], 201);
    }

    private function priorityFor(string $category, ?string $requestedPriority, array $metadata = []): string
    {
        if ($requestedPriority === 'critical') {
            return 'critical';
        }

        if (in_array($category, ['payment', 'payout'], true) || $this->hasFinancialContext($metadata)) {
            return 'high';
        }

        return $requestedPriority ?? 'normal';
    }

    private function hasFinancialContext(array $metadata): bool
    {
        foreach (self::FINANCIAL_METADATA_KEYS as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                return true;
            }
        }

        return false;
    }
}
