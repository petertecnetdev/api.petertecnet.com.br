<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcosystemAccountController extends Controller
{
    private const SDK_VERSION = '2.0.0';
    private const TELEMETRY_SCHEMA = '2';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('profile');

        $memberships = $user->applications()->get()->keyBy('id');

        $applications = Application::query()
            ->active()
            ->visibleInLauncher()
            ->orderBy('launcher_order')
            ->orderBy('name')
            ->get()
            ->map(function (Application $application) use ($memberships) {
                $membership = $memberships->get($application->id);
                $membershipStatus = $membership?->pivot?->status;
                $memberAccess = $membership !== null && ($membershipStatus === null || $membershipStatus === 'active');
                $hasAccess = $memberAccess || (bool) $application->self_service_access;
                $operational = $application->isOperational();

                return [
                    'id' => (int) $application->id,
                    'slug' => $application->slug,
                    'name' => $application->name,
                    'description' => $application->description,
                    'url' => $application->url,
                    'logo' => $application->logo,
                    'version' => $application->version,
                    'category' => $application->category,
                    'launcher_order' => (int) $application->launcher_order,
                    'operational_status' => $application->operational_status ?: 'operational',
                    'maintenance_message' => $application->maintenance_message,
                    'ecosystem_sdk_version' => $application->ecosystem_sdk_version,
                    'has_access' => $hasAccess,
                    'available' => $operational,
                    'self_service_access' => (bool) $application->self_service_access,
                    'membership' => $membership ? [
                        'role' => $membership->pivot->role,
                        'status' => $membershipStatus,
                        'metadata' => $membership->pivot->metadata,
                        'joined_at' => $membership->pivot->joined_at,
                    ] : null,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'sdk' => [
                    'version' => self::SDK_VERSION,
                    'minimum_version' => self::SDK_VERSION,
                    'telemetry_schema' => self::TELEMETRY_SCHEMA,
                ],
                'generated_at' => now()->toIso8601String(),
                'account' => [
                    'id' => (int) $user->id,
                    'user_name' => $user->user_name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'email_verified_at' => $user->email_verified_at,
                    'profile' => $user->profile,
                ],
                'applications' => $applications,
                'accessible_applications' => $applications
                    ->where('has_access', true)
                    ->where('available', true)
                    ->values(),
            ],
        ]);
    }
}
