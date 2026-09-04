<?php

namespace App\Domain\Media\Http\Controllers;

use App\Domain\Media\Services\AmbientMediaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class AmbientMediaController extends Controller
{
    public function __construct(private readonly AmbientMediaService $media) {}

    public function publicShow(string $subjectType, int $subjectId)
    {
        return response()->json([
            'media' => $this->media->publicMedia($subjectType, $subjectId),
        ]);
    }

    public function manage(Request $request, string $subjectType, int $subjectId)
    {
        return response()->json([
            'media' => $this->media->managedMedia($subjectType, $subjectId, $request->user()),
        ]);
    }

    public function upsert(Request $request, string $subjectType, int $subjectId)
    {
        $data = $request->validate([
            'provider' => 'required|in:youtube,spotify,audio',
            'source_url' => 'required|url:http,https|max:2048',
            'title' => 'sometimes|nullable|string|max:255',
            'artist' => 'sometimes|nullable|string|max:255',
            'enabled' => 'sometimes|boolean',
            'autoplay' => 'sometimes|boolean',
            'loop' => 'sometimes|boolean',
            'volume' => 'sometimes|integer|min:0|max:100',
            'start_seconds' => 'sometimes|integer|min:0|max:86400',
        ]);

        $media = $this->media->upsert($subjectType, $subjectId, $request->user(), $data);

        return response()->json([
            'message' => 'Trilha ambiente salva com sucesso.',
            'media' => $media,
        ]);
    }

    public function destroy(Request $request, string $subjectType, int $subjectId)
    {
        $this->media->remove($subjectType, $subjectId, $request->user());

        return response()->json([
            'message' => 'Trilha removida. O conteúdo voltará a usar o comportamento padrão.',
        ]);
    }

    public function recommendations(Request $request, string $subjectType, int $subjectId)
    {
        return response()->json(
            $this->media->recommendations($subjectType, $subjectId, $request->user())
        );
    }
}
