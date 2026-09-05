<?php

namespace App\Http\Controllers;

use App\Services\EcosystemAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcosystemAccountController extends Controller
{
    public function __construct(private readonly EcosystemAccountService $ecosystemAccount)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->ecosystemAccount->payloadFor($request->user()),
        ]);
    }
}
