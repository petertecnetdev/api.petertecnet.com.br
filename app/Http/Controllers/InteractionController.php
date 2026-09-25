<?php

namespace App\Http\Controllers;

use App\Services\FrontendTelemetryService;
use App\Services\TelemetryEventSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InteractionController extends Controller
{
    public function storeBatch(Request $request, FrontendTelemetryService $telemetry): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.id' => ['required', 'string', 'max:100'],
            'events.*.type' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'events.*.timestamp' => ['required', 'date'],
            'events.*.page' => ['nullable', 'string', 'max:1000'],
            'events.*.route' => ['nullable', 'string', 'max:1000'],
            'events.*.screen' => ['nullable', 'string', 'max:1000'],
            'events.*.label' => ['nullable', 'string', 'max:200'],
            'events.*.target' => ['nullable', 'string', 'max:200'],
            'events.*.duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'events.*.device' => ['nullable', 'string', 'max:80'],
            'events.*.result' => ['nullable', 'string', 'max:80'],
            'events.*.metadata' => ['nullable', 'array'],
        ]);

        $data['events'] = array_map(
            static fn (array $event): array => TelemetryEventSchema::normalize($event),
            $data['events']
        );

        try {
            $user = Auth::guard('api')->user();
        } catch (\Throwable) {
            $user = null;
        }

        return response()->json($telemetry->storeBatch($request, $data, $user), 202);
    }
}
