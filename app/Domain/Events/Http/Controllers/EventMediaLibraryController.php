<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventMediaLibraryService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class EventMediaLibraryController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventMediaLibraryService $mediaLibrary,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'production_id' => 'nullable|integer|min:1',
            'type' => 'nullable|in:image,audio,video,file',
            'per_page' => 'nullable|integer|min:1|max:60',
        ]);

        return response()->json([
            'media' => $this->mediaLibrary->paginate(
                $this->context->id(),
                $request->user(),
                $data,
            ),
        ]);
    }

    public function show(Request $request, int $eventId)
    {
        return response()->json([
            'media' => $this->mediaLibrary->find(
                $this->context->id(),
                $request->user(),
                $eventId,
            ),
        ]);
    }

    public function download(Request $request, int $eventId)
    {
        return $this->mediaLibrary->download(
            $this->context->id(),
            $request->user(),
            $eventId,
        );
    }

    public function downloadSoundtrackItem(Request $request, int $eventId, string $itemId)
    {
        return $this->mediaLibrary->downloadSoundtrackItem(
            $this->context->id(),
            $request->user(),
            $eventId,
            $itemId,
        );
    }
}
