<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Social\Services\SocialPostService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class SocialPostController extends Controller
{
    public function __construct(private readonly SocialPostService $posts) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        return response()->json([
            'posts' => $this->posts->paginate(
                (int) $request->user()->id,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 12),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'array', 'max:4'],
            'media.*' => [
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
            ],
            'poll_question' => ['nullable', 'string', 'max:300'],
            'poll_options' => ['nullable', 'array', 'max:6'],
            'poll_options.*' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'message' => 'Publicação criada.',
            'post' => $this->posts->create(
                (int) $request->user()->id,
                $data,
                $request->file('media', []),
            ),
        ], 201);
    }

    public function destroy(Request $request, int $postId)
    {
        if (! $this->posts->deleteOwned($postId, (int) $request->user()->id)) {
            return response()->json(['message' => 'Publicação não encontrada.'], 404);
        }

        return response()->json(['message' => 'Publicação removida.']);
    }

    public function vote(Request $request, int $postId)
    {
        $data = $request->validate([
            'option_id' => ['required', 'integer'],
        ]);

        $post = $this->posts->vote(
            $postId,
            (int) $request->user()->id,
            (int) $data['option_id'],
        );

        if (! $post) {
            return response()->json(['message' => 'Publicação não encontrada.'], 404);
        }

        return response()->json([
            'message' => 'Voto registrado.',
            'post' => $post,
        ]);
    }
}
