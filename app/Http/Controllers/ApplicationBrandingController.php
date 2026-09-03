<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\ApplicationBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ApplicationBrandingController extends Controller
{
    public function __construct(private readonly ApplicationBrandingService $branding) {}

    public function show(string $slug): JsonResponse
    {
        $application = Application::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->firstOrFail();

        $version = (int) $application->branding_version;
        $payload = Cache::remember(
            "applications.{$application->id}.branding.v{$version}",
            now()->addMinutes(10),
            fn () => $this->branding->publicPayload($application)
        );

        return response()
            ->json([
                'application' => [
                    'id' => $application->id,
                    'slug' => $application->slug,
                    'name' => $application->name,
                ],
                'branding' => $payload,
            ])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600')
            ->setEtag('branding-' . $application->id . '-' . $version);
    }
}
