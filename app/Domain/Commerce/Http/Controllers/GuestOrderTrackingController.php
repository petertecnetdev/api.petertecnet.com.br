<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\GuestOrderTrackingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestOrderTrackingController extends Controller
{
    public function __construct(private readonly GuestOrderTrackingService $tracking)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        return response()->json([
            'data' => $this->tracking->track(
                (int) $validated['order_id'],
                (string) $validated['phone'],
            ),
        ]);
    }
}
