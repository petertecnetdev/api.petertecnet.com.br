<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\EventItemRedemptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class EventItemRedemptionController extends Controller
{
    public function __construct(private readonly EventItemRedemptionService $redemptions) {}

    public function credential(Request $request, string $publicId)
    {
        return response()->json([
            'credential' => $this->redemptions->credentialFor($request->user(), $publicId),
        ]);
    }

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:180',
            'event_id' => 'required|integer|exists:events,id',
        ]);

        return response()->json(
            $this->redemptions->redeem($request->user(), $data['token'], (int) $data['event_id'])
        );
    }
}
