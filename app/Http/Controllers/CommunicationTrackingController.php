<?php

namespace App\Http\Controllers;

use App\Domain\Engagement\Services\CommunicationTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationTrackingController extends Controller
{
    public function __construct(private readonly CommunicationTrackingService $tracking)
    {
    }

    public function click(Request $request, string $communicationId): RedirectResponse
    {
        $target = $this->tracking->trackClick(
            communicationId: $communicationId,
            encodedTarget: (string) $request->query('target', ''),
            cta: (string) $request->query('cta', ''),
            entityId: (int) $request->query('entity_id', 0) ?: null,
            appId: (int) $request->query('app_id', 0) ?: null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->away($target);
    }

    public function open(Request $request, string $communicationId): Response
    {
        $this->tracking->trackOpen(
            communicationId: $communicationId,
            entityId: (int) $request->query('entity_id', 0) ?: null,
            appId: (int) $request->query('app_id', 0) ?: null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true) ?: '';

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
