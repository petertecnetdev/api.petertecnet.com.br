<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Services\SubscriptionIntentCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionIntentController extends Controller
{
    public function store(Request $request, string $application, SubscriptionIntentCreationService $intents): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'],
            'source' => ['nullable', 'string', 'max:120'],
            'handoff_channel' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array', 'max:25'],
            'metadata.client_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'metadata.client_price_cents' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'metadata.currency' => ['nullable', 'string', 'size:3'],
            'metadata.page' => ['nullable', 'string', 'max:255'],
            'metadata.referral' => ['nullable', 'string', 'max:120'],
            'metadata.campaign' => ['nullable', 'string', 'max:120'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            return response()->json([
                'message' => 'Idempotency-Key header is required and must have at most 120 characters.',
            ], 422);
        }

        $result = $intents->create($application, $validated, (int) $request->user()->getAuthIdentifier(), $idempotencyKey);
        return response()->json($result['body'], $result['status']);
    }
}
