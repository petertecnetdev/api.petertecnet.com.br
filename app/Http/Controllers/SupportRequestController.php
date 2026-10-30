<?php

namespace App\Http\Controllers;

use App\Services\SupportRequestIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportRequestController extends Controller
{
    public function store(Request $request, SupportRequestIntakeService $intake): JsonResponse
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

        $result = $intake->record($request, $data);
        return response()->json(['data' => $result['support'], 'correlation_id' => $result['correlation_id']], 201);
    }

}
