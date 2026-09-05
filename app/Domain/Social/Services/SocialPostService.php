<?php

namespace App\Domain\Social\Services;

use App\Support\ApplicationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SocialPostService
{
    private const MAX_OPTIONS = 6;

    public function __construct(private readonly ApplicationContext $context) {}

    public function paginate(int $viewerUserId, int $page = 1, int $perPage = 12)
    {
        $posts = $this->basePostQuery()
            ->orderByDesc('posts.created_at')
            ->orderByDesc('posts.id')
            ->paginate(max(1, min($perPage, 30)), ['*'], 'page', max(1, $page));

        $posts->setCollection($this->hydratePosts(collect($posts->items()), $viewerUserId));

        return $posts;
    }

    public function create(int $userId, array $data, mixed $mediaFiles = []): ?array
    {
        $body = trim((string) ($data['body'] ?? ''));
        $pollQuestion = trim((string) ($data['poll_question'] ?? ''));
        $pollOptions = $this->normalizePollOptions($data['poll_options'] ?? []);
        $mediaFiles = is_array($mediaFiles) ? array_values(array_filter($mediaFiles)) : array_values(array_filter([$mediaFiles]));

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

        if ($body === '' && $mediaFiles === [] && $pollQuestion === '') {
            throw ValidationException::withMessages([
                'body' => 'Escreva um texto, adicione uma foto ou vídeo, ou crie uma enquete.',
            ]);
        }

        $appId = $this->context->id();
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

            foreach ($mediaFiles as $position => $file) {
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

            if ($storedPaths !== []) {
                Storage::disk('public')->delete($storedPaths);
            }

            throw $exception;
        }

        return $this->findHydratedPost((int) $postId, $userId);
    }

    public function deleteOwned(int $postId, int $userId): bool
    {
        $post = DB::table('social_posts')
            ->where('app_id', $this->context->id())
            ->where('id', $postId)
            ->where('user_id', $userId)
            ->first();

        if (! $post) {
            return false;
        }

        $paths = DB::table('social_post_media')
            ->where('app_id', $this->context->id())
            ->where('post_id', $postId)
            ->pluck('path')
            ->filter()
            ->values()
            ->all();

        DB::table('social_posts')->where('id', $postId)->delete();

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }

        return true;
    }

    public function vote(int $postId, int $userId, int $optionId): ?array
    {
        $appId = $this->context->id();
        $post = DB::table('social_posts')
            ->where('app_id', $appId)
            ->where('id', $postId)
            ->where('status', 'published')
            ->first();

        if (! $post) {
            return null;
        }

        $poll = DB::table('social_post_polls')
            ->where('app_id', $appId)
            ->where('post_id', $postId)
            ->first();

        if (! $poll) {
            throw ValidationException::withMessages([
                'option_id' => 'Esta publicação não possui enquete.',
            ]);
        }

        if ($poll->closes_at && now()->greaterThan($poll->closes_at)) {
            throw ValidationException::withMessages([
                'option_id' => 'Esta enquete já foi encerrada.',
            ]);
        }

        $optionExists = DB::table('social_post_poll_options')
            ->where('app_id', $appId)
            ->where('poll_id', $poll->id)
            ->where('id', $optionId)
            ->exists();

        if (! $optionExists) {
            throw ValidationException::withMessages([
                'option_id' => 'Opção de enquete inválida.',
            ]);
        }

        $existingVote = DB::table('social_post_poll_votes')
            ->where('app_id', $appId)
            ->where('poll_id', $poll->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingVote) {
            DB::table('social_post_poll_votes')
                ->where('id', $existingVote->id)
                ->update([
                    'option_id' => $optionId,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('social_post_poll_votes')->insert([
                'app_id' => $appId,
                'poll_id' => $poll->id,
                'option_id' => $optionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->findHydratedPost($postId, $userId);
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

        if ($pollIds !== []) {
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
            $displayName = $displayName !== '' ? $displayName : ((string) ($post->author_user_name ?: 'Usuário'));

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
