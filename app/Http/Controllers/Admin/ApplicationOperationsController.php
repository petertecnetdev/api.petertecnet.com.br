<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Admin\ApplicationOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationOperationsController extends Controller
{
    public function show(Request $request, Application $application, ApplicationOperationsService $service): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json($service->dashboard($application, (int) ($validated['days'] ?? 30)));
    }
}
