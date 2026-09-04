<?php

namespace App\Domain\MarketData\Http\Controllers;

use App\Domain\MarketData\Services\MarketDataService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MarketDataController extends Controller
{
    public function __construct(private readonly MarketDataService $marketData) {}

    public function candles(Request $request, string $application, string $asset): JsonResponse
    {
        $validated = $request->validate([
            'quote' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9]+$/'],
            'interval' => ['nullable', 'in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,3d,1w,1M'],
            'limit' => ['nullable', 'integer', 'min:20', 'max:500'],
        ]);

        try {
            $data = $this->marketData->candles(
                $asset,
                (string) ($validated['quote'] ?? 'USDT'),
                (string) ($validated['interval'] ?? '1h'),
                (int) ($validated['limit'] ?? 200),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => 'MARKET_DATA_UNAVAILABLE',
                'request_id' => $request->attributes->get('request_id'),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
