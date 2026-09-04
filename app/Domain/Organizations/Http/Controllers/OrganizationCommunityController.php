<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Domain\Organizations\Services\OrganizationCommunityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class OrganizationCommunityController extends Controller
{
    public function __construct(private readonly OrganizationCommunityService $service) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);

        return response()->json([
            'posts' => $this->service->publicCommunity($slug, $request->bearerToken(), $perPage),
        ]);
    }

    public function createPost(Request $request, int $organizationId)
    {
        $data = $request->validate([
            'body' => 'required|string|min:2|max:3000',
            'parent_id' => 'nullable|integer|min:1',
        ]);

        return response()->json(
            $this->service->createPost($organizationId, $request->user(), $data),
            201
        );
    }

    public function deletePost(Request $request, int $postId)
    {
        $this->service->deletePost($postId, $request->user());

        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $this->service->like($postId, $request->user());

        return response()->json(['message' => 'Publicação curtida.', 'liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        $this->service->unlike($postId, $request->user());

        return response()->json(['message' => 'Curtida removida.', 'liked' => false]);
    }

    public function storeMedia(Request $request, int $organizationId)
    {
        $data = $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
            'caption' => 'nullable|string|max:180',
        ]);
        $media = $this->service->storeMedia(
            $organizationId,
            $request->user(),
            $data['photo'],
            $data['caption'] ?? null
        );

        return response()->json(['message' => 'Foto adicionada.', 'media' => $media], 201);
    }

    public function deleteMedia(Request $request, int $organizationId, int $mediaId)
    {
        $this->service->deleteMedia($organizationId, $mediaId, $request->user());

        return response()->json(['message' => 'Foto removida.']);
    }
}
