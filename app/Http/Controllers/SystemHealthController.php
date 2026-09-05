<?php

namespace App\Http\Controllers;

use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;

class SystemHealthController extends Controller
{
    public function live(SystemHealthService $health): JsonResponse
    {
        return response()->json($health->live());
    }

    public function ready(SystemHealthService $health): JsonResponse
    {
        $result = $health->ready();

        return response()->json($result, $result['ready'] ? 200 : 503);
    }
}
