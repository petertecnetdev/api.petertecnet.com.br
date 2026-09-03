<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EcosystemAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('profile');

        $memberships = $user->applications()->get()->keyBy('id');

        $applications = Application::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (Application $application) use ($memberships) {
                $membership = $memberships->get($application->id);
                $status = $membership?->pivot?->status;
                $memberAccess = $membership !== null && ($status === null || $status === 'active');
                $hasAccess = $memberAccess || (bool) $application->self_service_access;

                return [
                    'id' => (int) $application->id,
                    'slug' => $application->slug,
                    'name' => $application->name,
                    'description' => $application->description,
                    'url' => $application->url,
                    'logo' => $application->logo,
                    'version' => $application->version,
                    'has_access' => $hasAccess,
                    'self_service_access' => (bool) $application->self_service_access,
                    'membership' => $membership ? [
                        'role' => $membership->pivot->role,
                        'status' => $status,
                        'metadata' => $membership->pivot->metadata,
                        'joined_at' => $membership->pivot->joined_at,
                    ] : null,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
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
                'accessible_applications' => $applications->where('has_access', true)->values(),
            ],
        ]);
    }
}
