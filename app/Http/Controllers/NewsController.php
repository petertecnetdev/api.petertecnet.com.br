<?php

namespace App\Http\Controllers;

use App\Models\Interaction;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class NewsController extends Controller
{
    public function list(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $query = News::query()->with('user:id,first_name,last_name,user_name,avatar');

        if ($search !== '') {
            $query->where(function ($scope) use ($search) {
                $scope->where('title', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate($data['per_page'] ?? 6)
        );
    }

    public function search(Request $request)
    {
        return $this->list($request);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (! $this->allowed($user, 'blog_create')) {
            return response()->json(['error' => 'Você não tem permissão para criar notícias.'], 403);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:100000',
            'published_at' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $imagePath = $request->hasFile('image')
            ? $this->storeImage($request->file('image'))
            : null;

        $news = News::create([
            'title' => $data['title'],
            'content' => $data['content'],
            'published_at' => $data['published_at'] ?? null,
            'image' => $imagePath,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Notícia criada com sucesso.',
            'news' => $news,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $news = News::findOrFail($id);
        $user = Auth::user();

        if (! $this->canManage($user, $news, 'blog_edit')) {
            return response()->json(['error' => 'Você não tem permissão para editar esta notícia.'], 403);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string|max:100000',
            'published_at' => 'sometimes|nullable|date',
            'image' => 'sometimes|nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $update = collect($data)->except('image')->all();

        if ($request->hasFile('image')) {
            $oldImage = $news->image;
            $update['image'] = $this->storeImage($request->file('image'));
            $this->deleteImage($oldImage);
        }

        $news->update($update);

        return response()->json([
            'message' => 'Notícia atualizada com sucesso.',
            'news' => $news->fresh(),
        ]);
    }

    public function show($id)
    {
        $news = News::query()
            ->with([
                'user:id,first_name,last_name,user_name,avatar',
                'comments.user:id,first_name,last_name,user_name,avatar',
            ])
            ->findOrFail($id);

        $recommendedNews = News::query()
            ->where('id', '!=', $news->id)
            ->whereNotNull('published_at')
            ->inRandomOrder()
            ->limit(3)
            ->get();

        return response()->json([
            'news' => $news,
            'recommended_news' => $recommendedNews,
        ]);
    }

    public function destroy($id)
    {
        $news = News::findOrFail($id);
        $user = Auth::user();

        if (! $this->canManage($user, $news, 'blog_delete')) {
            return response()->json(['error' => 'Você não tem permissão para excluir esta notícia.'], 403);
        }

        $image = $news->image;
        $news->delete();
        $this->deleteImage($image);

        return response()->json(['message' => 'Notícia deletada com sucesso.']);
    }

    public function comment(Request $request, $newsId)
    {
        News::findOrFail($newsId);

        $data = $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $interaction = Interaction::create([
            'user_id' => Auth::id(),
            'entity_id' => $newsId,
            'entity_type' => 'news',
            'interaction_type' => 'comment',
            'comment' => $data['comment'],
        ]);

        return response()->json([
            'message' => 'Comentário adicionado com sucesso.',
            'interaction' => $interaction->load('user:id,first_name,last_name,user_name,avatar'),
        ], 201);
    }

    private function storeImage($uploaded): string
    {
        $path = 'images/news/' . uuid_create(UUID_TYPE_RANDOM) . '.webp';
        $absolute = Storage::disk('public')->path($path);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        Image::make($uploaded->getRealPath())
            ->orientate()
            ->fit(660, 441)
            ->encode('webp', 85)
            ->save($absolute);

        return $path;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'images/news/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function canManage($user, News $news, string $permission): bool
    {
        return $user && (
            $user->hasProfile('Administrador')
            || (int) $news->user_id === (int) $user->id
            || $user->hasPermission($permission)
        );
    }

    private function allowed($user, string $permission): bool
    {
        return $user && ($user->hasProfile('Administrador') || $user->hasPermission($permission));
    }
}
