<?php

namespace App\Http\Controllers;

use App\Services\ApplicationContextService;
use App\Services\Support\SupportRequestIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportRequestController extends Controller
{
    public function store(Request $request, ApplicationContextService $context, SupportRequestIntakeService $intake): JsonResponse
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

        $support = $intake->create($request, $data, $context);

        return response()->json([
            'data' => $support,
            'correlation_id' => $support->correlation_id,
        ], 201);
    }
}
