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
        $reverbKey = trim((string) config('reverb.apps.apps.0.key'));
        $reverbHost = trim((string) config('reverb.apps.apps.0.options.host'));
        $reverbPort = (int) config('reverb.apps.apps.0.options.port', 443);
        $reverbScheme = (string) config('reverb.apps.apps.0.options.scheme', 'https');

        return response()->json([
            'google_client_id' => $googleClientId,
            'google_configured' => $googleClientId !== '',
            'realtime' => [
                'configured' => $reverbKey !== '' && $reverbHost !== '',
                'key' => $reverbKey !== '' ? $reverbKey : null,
                'host' => $reverbHost !== '' ? $reverbHost : null,
                'port' => $reverbPort,
                'scheme' => $reverbScheme,
            ],
            'application' => [
                'id' => $application->id,
                'slug' => $application->slug,
                'name' => $application->name,
                'url' => $application->url,
                'logo' => $application->logo,
                'version' => $application->version,
                'capabilities' => $this->context->capabilities(),
            ],
        ]);
    }
}
