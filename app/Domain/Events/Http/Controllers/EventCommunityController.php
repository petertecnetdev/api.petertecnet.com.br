<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class EventCommunityController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $event = $this->publicEventBySlug($slug);
        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);

        $posts = DB::table('event_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)->where('p.event_id', $event->id)->whereNull('p.parent_id')->where('p.status', 'published')
            ->select(['p.id', 'p.event_id', 'p.user_id', 'p.body', 'p.post_type', 'p.media', 'p.poll', 'p.is_pinned', 'p.created_at', 'p.edited_at', 'u.first_name', 'u.last_name', 'u.avatar'])
            ->selectSub(fn ($q) => $q->from('event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('event_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published'), 'comments_count')
            ->orderByDesc('p.is_pinned')->orderByDesc('p.created_at')->paginate($perPage);

        $ids = collect($posts->items())->pluck('id')->filter()->values();
        $replies = $ids->isEmpty() ? collect() : DB::table('event_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)->where('p.event_id', $event->id)->whereIn('p.parent_id', $ids)->where('p.status', 'published')
            ->select(['p.id', 'p.parent_id', 'p.user_id', 'p.body', 'p.created_at', 'p.edited_at', 'u.first_name', 'u.last_name', 'u.avatar'])
            ->selectSub(fn ($q) => $q->from('event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->orderBy('p.created_at')->get()->groupBy('parent_id');

        $liked = collect();
        if ($user) {
            $all = $ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();
            if ($all->isNotEmpty()) {
                $liked = DB::table('event_post_likes')->where('app_id', $appId)->where('user_id', $user->id)->whereIn('post_id', $all)->pluck('post_id');
            }
        }

        $pollVotes = $this->pollVoteSummary($ids, $user?->id);
        $posts->setCollection(collect($posts->items())->map(function ($post) use ($replies, $liked, $user, $pollVotes) {
            $post = $this->hydrateRichPost($post, $pollVotes[$post->id] ?? null);
            $post->is_liked = $user ? $liked->contains($post->id) : false;
            $post->replies = collect($replies->get($post->id, []))->map(function ($reply) use ($liked, $user) {
                $reply->is_liked = $user ? $liked->contains($reply->id) : false;
                return $reply;
            })->values();
            return $post;
        }));

        $rating = DB::table('event_ratings')->where('app_id', $appId)->where('event_id', $event->id)->selectRaw('ROUND(AVG(rating),1) average, COUNT(*) total')->first();
        $mine = $user ? DB::table('event_ratings')->where(['app_id' => $appId, 'event_id' => $event->id, 'user_id' => $user->id])->value('rating') : null;

        return response()->json(['posts' => $posts, 'rating' => ['average' => $rating?->average ? (float) $rating->average : 0, 'total' => (int) ($rating?->total ?? 0), 'mine' => $mine ? (int) $mine : null]]);
    }

    public function createPost(Request $request, int $eventId)
    {
        $user = $request->user();
        $event = $this->publicEventById($eventId);
        $appId = $this->context->id();

        if (is_string($request->input('poll'))) {
            $decoded = json_decode((string) $request->input('poll'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['poll' => $decoded]);
            }
        }

        $data = $request->validate([
            'body' => 'nullable|string|max:3000',
            'parent_id' => 'nullable|integer|min:1',
            'post_type' => ['nullable', Rule::in(['text', 'image', 'video', 'poll'])],
            'media' => 'nullable|file|max:51200|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
            'poll' => 'nullable|array',
            'poll.question' => 'required_with:poll|string|min:2|max:280',
            'poll.options' => 'required_with:poll|array|min:2|max:8',
            'poll.options.*' => 'required|string|min:1|max:120|distinct',
            'poll.expires_at' => 'nullable|date|after:now',
        ]);

        $parent = null;
        if ($parentId = $data['parent_id'] ?? null) {
            $parent = DB::table('event_posts')->where('id', $parentId)->where('app_id', $appId)->where('event_id', $event->id)->whereNull('parent_id')->where('status', 'published')->first();
            abort_unless($parent, 422, 'A publicação que você tentou responder não está mais disponível.');
        }

        $body = trim((string) ($data['body'] ?? ''));
        $type = $parent ? 'text' : (string) ($data['post_type'] ?? 'text');
        $mediaPayload = null;
        $pollPayload = null;

        if (!$parent && $request->hasFile('media')) {
            $file = $request->file('media');
            $mime = (string) $file->getMimeType();
            $detectedType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            abort_if($type !== $detectedType, 422, 'O tipo da mídia não corresponde ao tipo da publicação.');
            $path = $file->store("event-community/{$appId}/{$event->id}", 'public');
            $mediaPayload = [
                'type' => $detectedType,
                'url' => Storage::disk('public')->url($path),
                'mime_type' => $mime,
                'size' => (int) $file->getSize(),
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 180),
            ];
        }

        if (!$parent && $type === 'poll') {
            abort_unless(isset($data['poll']['options']), 422, 'Adicione pelo menos duas opções à enquete.');
            $pollPayload = [
                'question' => trim((string) $data['poll']['question']),
                'options' => collect($data['poll']['options'])->values()->map(fn ($label, $index) => ['id' => $index + 1, 'label' => trim((string) $label)])->all(),
                'expires_at' => $data['poll']['expires_at'] ?? null,
            ];
        }

        abort_if($body === '' && !$mediaPayload && !$pollPayload, 422, 'Escreva algo ou adicione uma foto, vídeo ou enquete para publicar.');
        abort_if($type === 'text' && $body === '', 422, 'Escreva pelo menos 2 caracteres para publicar.');
        if ($type === 'text') {
            abort_if(mb_strlen($body) < 2, 422, 'Escreva pelo menos 2 caracteres para publicar.');
        }

        $id = DB::table('event_posts')->insertGetId([
            'app_id' => $appId,
            'event_id' => $event->id,
            'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body' => $body,
            'post_type' => $type,
            'media' => $mediaPayload ? json_encode($mediaPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'poll' => $pollPayload ? json_encode($pollPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'status' => 'published',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifyCommunityActivity($event, $user, $id, $parent);

        return response()->json([
            'message' => $parent ? 'Comentário publicado.' : 'Publicação adicionada ao evento.',
            'post_id' => $id,
            'post_type' => $type,
        ], 201);
    }

    public function votePoll(Request $request, int $postId)
    {
        $user = $request->user();
        $post = $this->publishedPost($postId);
        $poll = json_decode((string) ($post->poll ?? ''), true);
        abort_unless(is_array($poll) && !empty($poll['options']), 422, 'Esta publicação não possui uma enquete ativa.');

        if (!empty($poll['expires_at'])) {
            abort_if(now()->greaterThanOrEqualTo($poll['expires_at']), 422, 'Esta enquete já foi encerrada.');
        }

        $optionIds = collect($poll['options'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $data = $request->validate(['option_id' => ['required', 'integer', Rule::in($optionIds)]]);
        $appId = $this->context->id();

        DB::table('event_post_poll_votes')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId, 'user_id' => $user->id],
            ['option_id' => (int) $data['option_id'], 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json([
            'message' => 'Voto registrado.',
            'poll' => $this->pollVoteSummary(collect([$postId]), $user->id)[$postId] ?? ['total_votes' => 0, 'votes' => [], 'my_option_id' => (int) $data['option_id']],
        ]);
    }

    public function deletePost(Request $request, int $postId)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $post = DB::table('event_posts')->where('app_id', $appId)->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        $event = Event::with('production')->where('app_id', $appId)->find($post->event_id);
        $can = (int) $post->user_id === (int) $user->id || $user->hasProfile('Administrador') || ($event?->production && (int) $event->production->user_id === (int) $user->id);
        abort_unless($can, 403, 'Você não tem permissão para remover esta publicação.');
        DB::table('event_posts')->where('app_id', $appId)->where(fn ($q) => $q->where('id', $postId)->orWhere('parent_id', $postId))->update(['status' => 'hidden', 'updated_at' => now()]);
        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $user = $request->user();
        $post = $this->publishedPost($postId);
        $appId = $this->context->id();
        $already = DB::table('event_post_likes')->where(['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id])->exists();
        DB::table('event_post_likes')->updateOrInsert(['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);
        if (!$already && (int) $post->user_id !== (int) $user->id) {
            $event = Event::where('app_id', $appId)->find($post->event_id);
            if ($event) {
                $this->safeNotify($appId, (int) $post->user_id, ['type' => 'comment_like', 'title' => 'Curtiram sua publicação', 'message' => (trim((string) $user->first_name) ?: 'Alguém').' curtiu o que você publicou em '.$event->title.'.', 'reference_type' => 'event', 'reference_id' => $event->id, 'reference_url' => '/event/'.$event->slug.'#comunidade', 'data' => ['event_id' => $event->id, 'post_id' => $post->id, 'actor_id' => $user->id]]);
            }
        }
        return response()->json(['message' => 'Publicação curtida.', 'liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        DB::table('event_post_likes')->where(['app_id' => $this->context->id(), 'post_id' => $postId, 'user_id' => $request->user()->id])->delete();
        return response()->json(['message' => 'Curtida removida.', 'liked' => false]);
    }

    public function rate(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5']);
        $verified = EventPass::where('event_id', $event->id)->where('user_id', $request->user()->id)->exists();
        DB::table('event_ratings')->updateOrInsert(['app_id' => $this->context->id(), 'event_id' => $event->id, 'user_id' => $request->user()->id], ['rating' => (int) $data['rating'], 'verified_attendee' => $verified, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Sua avaliação foi registrada.', 'rating' => (int) $data['rating'], 'verified_attendee' => $verified]);
    }

    public function report(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        $data = $request->validate(['reason' => 'required|in:fraud,misleading,inappropriate,safety,cancelled,illegal,hate,harassment,spam,copyright,other', 'details' => 'nullable|string|max:3000']);
        DB::table('event_reports')->updateOrInsert(['app_id' => $this->context->id(), 'event_id' => $event->id, 'user_id' => $request->user()->id], ['reason' => $data['reason'], 'details' => trim((string) ($data['details'] ?? '')) ?: null, 'status' => 'open', 'reviewed_by' => null, 'reviewed_at' => null, 'moderation_note' => null, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Denúncia enviada para revisão.']);
    }

    private function hydrateRichPost(object $post, ?array $pollSummary = null): object
    {
        $post->post_type = $post->post_type ?: 'text';
        $post->media = $post->media ? json_decode((string) $post->media, true) : null;
        $poll = $post->poll ? json_decode((string) $post->poll, true) : null;
        if (is_array($poll)) {
            $votes = collect($pollSummary['votes'] ?? [])->keyBy('option_id');
            $total = (int) ($pollSummary['total_votes'] ?? 0);
            $poll['options'] = collect($poll['options'] ?? [])->map(function ($option) use ($votes, $total) {
                $count = (int) (($votes[(int) ($option['id'] ?? 0)]['votes_count'] ?? 0));
                return [...$option, 'votes_count' => $count, 'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0];
            })->values()->all();
            $poll['total_votes'] = $total;
            $poll['my_option_id'] = $pollSummary['my_option_id'] ?? null;
        }
        $post->poll = $poll;
        return $post;
    }

    private function pollVoteSummary($postIds, ?int $userId = null): array
    {
        $ids = collect($postIds)->filter()->map(fn ($id) => (int) $id)->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $appId = $this->context->id();
        $rows = DB::table('event_post_poll_votes')
            ->where('app_id', $appId)->whereIn('post_id', $ids)
            ->selectRaw('post_id, option_id, COUNT(*) as votes_count')
            ->groupBy('post_id', 'option_id')->get()->groupBy('post_id');
        $mine = $userId ? DB::table('event_post_poll_votes')->where('app_id', $appId)->where('user_id', $userId)->whereIn('post_id', $ids)->pluck('option_id', 'post_id') : collect();

        return $ids->mapWithKeys(function ($postId) use ($rows, $mine) {
            $votes = collect($rows->get($postId, []))->map(fn ($row) => ['option_id' => (int) $row->option_id, 'votes_count' => (int) $row->votes_count])->values();
            return [$postId => ['total_votes' => $votes->sum('votes_count'), 'votes' => $votes->all(), 'my_option_id' => isset($mine[$postId]) ? (int) $mine[$postId] : null]];
        })->all();
    }

    private function notifyCommunityActivity(Event $event, User $actor, int $postId, ?object $parent): void
    {
        $appId = $this->context->id();
        $parentAuthor = $parent ? (int) $parent->user_id : null;
        $attendees = EventPass::where('event_id', $event->id)->whereNotNull('user_id')->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $actor->id || ($parentAuthor && $id === $parentAuthor))->values();
        $name = trim((string) $actor->first_name) ?: 'Alguém';
        $payload = ['type' => $parent ? 'event_reply' : 'event_comment', 'title' => $parent ? 'Nova resposta no evento' : 'Nova publicação no evento', 'message' => $name.($parent ? ' respondeu uma conversa em ' : ' publicou na timeline de ').$event->title.'.', 'reference_type' => 'event', 'reference_id' => $event->id, 'reference_url' => '/event/'.$event->slug.'#comunidade', 'data' => ['event_id' => $event->id, 'post_id' => $postId, 'actor_id' => $actor->id]];
        try {
            $this->notifications->sendToUsers($appId, $attendees, $payload, (int) $actor->id);
        } catch (Throwable $e) {
            report($e);
        }
        if ($parentAuthor && $parentAuthor !== (int) $actor->id) {
            $this->safeNotify($appId, $parentAuthor, $payload);
        }
    }

    private function safeNotify(int $appId, int $userId, array $payload): void
    {
        try {
            $this->notifications->sendToUser($appId, $userId, $payload);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function publishedPost(int $id): object
    {
        $post = DB::table('event_posts')->where('app_id', $this->context->id())->where('id', $id)->where('status', 'published')->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function publicEventBySlug(string $slug): Event
    {
        return Event::where('app_id', $this->context->id())->where('slug', $slug)->where('is_published', true)->where('is_cancelled', false)->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))->firstOrFail();
    }

    private function publicEventById(int $id): Event
    {
        return Event::where('app_id', $this->context->id())->whereKey($id)->where('is_published', true)->where('is_cancelled', false)->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))->firstOrFail();
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }
        try {
            $user = JWTAuth::setToken($token)->authenticate();
            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }
}
