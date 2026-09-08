<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventSocialPreviewService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class EventSocialPreviewController extends Controller
{
    public function __construct(private readonly EventSocialPreviewService $preview) {}

    public function show(string $slug): Response
    {
        $event = $this->preview->findPublicEvent($slug);

        return response()
            ->view('social.event-preview', $this->preview->metadata($event))
            ->header('Cache-Control', 'public, max-age=120, s-maxage=300')
            ->header('X-Robots-Tag', 'noindex, follow');
    }

    public function image(string $slug): BinaryFileResponse|RedirectResponse
    {
        $event = $this->preview->findPublicEvent($slug);
        $path = $this->preview->previewImagePath($event);

        if ($path) {
            return response()->file($path, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400, immutable',
            ]);
        }

        $fallback = $this->preview->sourceImageUrl($event);
        abort_if($fallback === '', 404);

        return redirect()->away($fallback);
    }
}
