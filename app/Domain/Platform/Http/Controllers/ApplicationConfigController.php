<?php

namespace App\Domain\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;

final class ApplicationConfigController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(): JsonResponse
    {
        $application = $this->context->application();
        $googleClientId = trim((string) config('services.google.client_id'));

        return response()->json([
            'google_client_id' => $googleClientId,
            'google_configured' => $googleClientId !== '',
            'application' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'url' => $application->url,
                'logo' => $application->logo,
                'version' => $application->version,
            ],
        ]);
    }
}
