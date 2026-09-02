<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Events\Services\CheckInService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CheckInResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class CheckInController extends Controller
{
    public function __construct(private readonly CheckInService $checkIns) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'credential' => ['required','string','max:512'],
            'device_id' => ['nullable','string','max:190'],
        ]);

        $checkIn = $this->checkIns->checkIn(
            $data['credential'],
            $request->user()?->id,
            $data['device_id'] ?? null,
            $request->attributes->get('request_id')
        );

        return ApiResponse::success((new CheckInResource($checkIn))->resolve($request), [], 201);
    }
}
