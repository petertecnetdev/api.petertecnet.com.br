<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\Admin\ApplicationOperationsService;
use App\Services\ApplicationRuntimeControlService;
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

    public function runtime(Application $application, ApplicationRuntimeControlService $runtime): JsonResponse
    {
        return response()->json([
            'application' => [
                'id' => (int) $application->id,
                'name' => $application->name,
                'slug' => $application->slug,
            ],
            'runtime' => $runtime->settings($application),
        ]);
    }

    public function updateRuntime(Request $request, Application $application, ApplicationRuntimeControlService $runtime): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', 'in:off,on_demand,normal,realtime'],
            'processing_enabled' => ['sometimes', 'boolean'],
            'scheduled_processing_enabled' => ['sometimes', 'boolean'],
            'market_scanner_enabled' => ['sometimes', 'boolean'],
            'ai_enabled' => ['sometimes', 'boolean'],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'emails_enabled' => ['sometimes', 'boolean'],
            'reports_enabled' => ['sometimes', 'boolean'],
            'realtime_enabled' => ['sometimes', 'boolean'],
            'scan_interval_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'idle_timeout_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'application' => [
                'id' => (int) $application->id,
                'name' => $application->name,
                'slug' => $application->slug,
            ],
            'runtime' => $runtime->update($application, $validated, $request->user()?->id),
            'message' => 'Controle operacional atualizado.',
        ]);
    }
}
