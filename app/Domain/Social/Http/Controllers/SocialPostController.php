<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SocialPostController extends Controller
{
    private const MAX_MEDIA = 4;
    private const MAX_OPTIONS = 6;

    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 12);
        $userId = (int) $request->user()->id;

        $posts = $this->basePostQuery()
            ->orderByDesc('posts.created_at')
            ->orderByDesc('posts.id')
            ->paginate($perPage, ['*'], 'page', $page);

        $posts->setCollection($this->hydratePosts(collect($posts->items()), $userId));

        return response()->json(['posts' => $posts]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'array', 'max:'.self::MAX_MEDIA],
            'media.*' => [
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
            ],
            'poll_question' => ['nullable', 'string', 'max:300'],
            'poll_options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'poll_options.*' => ['nullable', 'string', 'max:120'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $pollQuestion = trim((string) ($data['poll_question'] ?? ''));
        $pollOptions = $this->normalizePollOptions($data['poll_options'] ?? []);
        $mediaFiles = $request->file('media', []);

        if (! is_array($mediaFiles)) {
            $mediaFiles = [$mediaFiles];
        }

        if ($pollQuestion !== '' && count($pollOptions) < 2) {
            throw ValidationException::withMessages([
                'poll_options' => 'Uma enquete precisa ter pelo menos duas opções diferentes.',
            ]);
        }

        if ($pollQuestion === '' && count($pollOptions) > 0) {
            throw ValidationException::withMessages([
                'poll_question' => 'Informe a pergunta da enquete.',
            ]);
        }

        if ($body === '' && count($mediaFiles) === 0 && $pollQuestion === '') {
            throw ValidationException::withMessages([
                'body' => 'Escreva um texto, adicione uma foto ou vídeo, ou crie uma enquete.',
            ]);
        }

        $appId = $this->context->id();
        $userId = (int) $request->user()->id;
        $storedPaths = [];
        $postId = null;

        DB::beginTransaction();

        try {
            $postId = DB::table('social_posts')->insertGetId([
                'app_id' => $appId,
                'user_id' => $userId,
                'body' => $body !== '' ? $body : null,
                'status' => 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_values($mediaFiles) as $position => $file) {
                if (! $file) {
                    continue;
                }

                $extension = strtolower((string) ($file->extension() ?: 'bin'));
                $filename = Str::uuid().'.'.$extension;
                $path = $file->storeAs(
                    'social/'.$this->context->slug().'/posts/'.$postId,
                    $filename,
                    'public'
                );
                $storedPaths[] = $path;

                $mimeType = (string) $file->getMimeType();
                $kind = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

                DB::table('social_post_media')->insert([
                    'app_id' => $appId,
                    'post_id' => $postId,
                    'kind' => $kind,
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                    'size_bytes' => (int) $file->getSize(),
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($pollQuestion !== '') {
                $pollId = DB::table('social_post_polls')->insertGetId([
                    'app_id' => $appId,
                    'post_id' => $postId,
                    'question' => $pollQuestion,
                    'closes_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($pollOptions as $position => $label) {
                    DB::table('social_post_poll_options')->insert([
                        'app_id' => $appId,
                        'poll_id' => $pollId,
                        'label' => $label,
                        'position' => $position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            if ($storedPaths) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Publicação criada.',
            'post' => $this->findHydratedPost((int) $postId, $userId),
        ], 201);
    }

    public function destroy(Request $request, int $postId)
    {
        $post = DB::table('social_posts')
            ->where('app_id', $this->context->id())
            ->where('id', $postId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $post) {
            return response()->json(['message' => 'Publicação não encontrada.'], 404);
        }

        $paths = DB::table('social_post_media')
            ->where('app_id', $this->context->id())
            ->where('post_id', $postId)
            ->pluck('path')
            ->filter()
            ->values()
            ->all();

        DB::table('social_posts')->where('id', $postId)->delete();

        if ($paths) {
            Storage::disk('public')->delete($paths);
        }

        return response()->json(['message' => 'Publicação removida.']);
    }

    public function vote(Request $request, int $postId)
    {
        $data = $request->validate([
            'option_id' => ['required', 'integer'],
        ]);

        $appId = $this->context->id();
        $userId = (int) $request->user()->id;

        $post = DB::table('social_posts')
            ->where('app_id', $appId)
            ->where('id', $postId)
            ->where('status', 'published')
            ->first();

        if (! $post) {
            return response()->json(['message' => 'Publicação não encontrada.'], 404);
        }

        $poll = DB::table('social_post_polls')
            ->where('app_id', $appId)
            ->where('post_id', $postId)
            ->first();

        if (! $poll) {
            return response()->json(['message' => 'Esta publicação não possui enquete.'], 422);
        }

        if ($poll->closes_at && now()->greaterThan($poll->closes_at)) {
            return response()->json(['message' => 'Esta enquete já foi encerrada.'], 422);
        }

        $optionExists = DB::table('social_post_poll_options')
            ->where('app_id', $appId)
            ->where('poll_id', $poll->id)
            ->where('id', (int) $data['option_id'])
            ->exists();

        if (! $optionExists) {
            throw ValidationException::withMessages([
                'option_id' => 'Opção de enquete inválida.',
            ]);
        }

        DB::table('social_post_poll_votes')->updateOrInsert(
            [
                'app_id' => $appId,
                'poll_id' => $poll->id,
                'user_id' => $userId,
            ],
            [
                'option_id' => (int) $data['option_id'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Voto registrado.',
            'post' => $this->findHydratedPost($postId, $userId),
        ]);
    }

    private function basePostQuery()
    {
        return DB::table('social_posts as posts')
            ->join('users as users', 'users.id', '=', 'posts.user_id')
            ->where('posts.app_id', $this->context->id())
            ->where('posts.status', 'published')
            ->select([
                'posts.id',
                'posts.user_id',
                'posts.body',
                'posts.created_at',
                'posts.updated_at',
                'users.user_name as author_user_name',
                'users.first_name as author_first_name',
                'users.last_name as author_last_name',
                'users.avatar as author_avatar',
            ]);
    }

    private function findHydratedPost(int $postId, int $viewerUserId): ?array
    {
        $post = $this->basePostQuery()->where('posts.id', $postId)->first();

        if (! $post) {
            return null;
        }

        return $this->hydratePosts(collect([$post]), $viewerUserId)->first();
    }

    private function hydratePosts(Collection $posts, int $viewerUserId): Collection
    {
        if ($posts->isEmpty()) {
            return collect();
        }

        $postIds = $posts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $appId = $this->context->id();

        $mediaByPost = DB::table('social_post_media')
            ->where('app_id', $appId)
            ->whereIn('post_id', $postIds)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('post_id');

        $polls = DB::table('social_post_polls')
            ->where('app_id', $appId)
            ->whereIn('post_id', $postIds)
            ->get()
            ->keyBy('post_id');

        $pollIds = $polls->pluck('id')->map(fn ($id) => (int) $id)->all();
        $optionsByPoll = collect();
        $selectedByPoll = collect();

        if ($pollIds) {
            $optionsByPoll = DB::table('social_post_poll_options as options')
                ->leftJoin('social_post_poll_votes as votes', 'votes.option_id', '=', 'options.id')
                ->where('options.app_id', $appId)
                ->whereIn('options.poll_id', $pollIds)
                ->groupBy('options.id', 'options.poll_id', 'options.label', 'options.position')
                ->orderBy('options.position')
                ->selectRaw('options.id, options.poll_id, options.label, options.position, COUNT(votes.id) as votes_count')
                ->get()
                ->groupBy('poll_id');

            $selectedByPoll = DB::table('social_post_poll_votes')
                ->where('app_id', $appId)
                ->where('user_id', $viewerUserId)
                ->whereIn('poll_id', $pollIds)
                ->get(['poll_id', 'option_id'])
                ->keyBy('poll_id');
        }

        return $posts->map(function ($post) use ($mediaByPost, $polls, $optionsByPoll, $selectedByPoll, $viewerUserId) {
            $first = trim((string) ($post->author_first_name ?? ''));
            $last = trim((string) ($post->author_last_name ?? ''));
            $displayName = trim($first.' '.$last);
            $displayName = $displayName !== '' ? $displayName : ((string) ($post->author_user_name ?: 'Usuário Cutinapp'));

            $media = collect($mediaByPost->get($post->id, []))->map(fn ($item) => [
                'id' => (int) $item->id,
                'kind' => $item->kind,
                'path' => $item->path,
                'mime_type' => $item->mime_type,
                'size_bytes' => (int) $item->size_bytes,
            ])->values();

            $pollPayload = null;
            $poll = $polls->get($post->id);

            if ($poll) {
                $options = collect($optionsByPoll->get($poll->id, []));
                $totalVotes = (int) $options->sum(fn ($option) => (int) $option->votes_count);
                $selected = $selectedByPoll->get($poll->id);

                $pollPayload = [
                    'id' => (int) $poll->id,
                    'question' => $poll->question,
                    'closes_at' => $poll->closes_at,
                    'total_votes' => $totalVotes,
                    'selected_option_id' => $selected ? (int) $selected->option_id : null,
                    'options' => $options->map(fn ($option) => [
                        'id' => (int) $option->id,
                        'label' => $option->label,
                        'votes_count' => (int) $option->votes_count,
                        'percentage' => $totalVotes > 0
                            ? (int) round(((int) $option->votes_count / $totalVotes) * 100)
                            : 0,
                    ])->values(),
                ];
            }

            return [
                'id' => (int) $post->id,
                'body' => $post->body,
                'created_at' => $post->created_at,
                'updated_at' => $post->updated_at,
                'is_owner' => (int) $post->user_id === $viewerUserId,
                'author' => [
                    'id' => (int) $post->user_id,
                    'name' => $displayName,
                    'user_name' => $post->author_user_name,
                    'avatar' => $post->author_avatar,
                ],
                'media' => $media,
                'poll' => $pollPayload,
            ];
        })->values();
    }

    private function normalizePollOptions(array $options): array
    {
        $normalized = [];
        $seen = [];

        foreach ($options as $option) {
            $label = trim((string) $option);
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $label;
        }

        return array_slice($normalized, 0, self::MAX_OPTIONS);
    }
}
