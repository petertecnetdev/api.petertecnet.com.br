<?php

namespace App\Domain\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\File;
use App\Models\Interaction;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\MediaVariantService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class EventCommunityController extends Controller
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
        private readonly MediaVariantService $mediaVariants,
    ) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $event = $this->publicEventBySlug($slug)->loadMissing('production');
        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        $access = $this->accessFor($event, $user);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);

        $posts = DB::table('event_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->where('p.event_id', $event->id)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->select([
                'p.id', 'p.event_id', 'p.user_id', 'p.parent_id', 'p.body', 'p.is_pinned',
                'p.created_at', 'p.edited_at', 'u.first_name', 'u.last_name', 'u.avatar',
            ])
            ->when(
                Schema::hasColumn('event_posts', 'file_id'),
                fn ($query) => $query->addSelect('p.file_id')
            )
            ->selectSub(
                fn ($q) => $q->from('event_post_likes as l')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('l.post_id', 'p.id')
                    ->where('l.app_id', $appId),
                'likes_count'
            )
            ->selectSub(
                fn ($q) => $q->from('event_posts as r')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('r.parent_id', 'p.id')
                    ->where('r.status', 'published'),
                'comments_count'
            )
            ->orderByDesc('p.is_pinned')
            ->orderByDesc('p.created_at')
            ->paginate($perPage);

        $ids = collect($posts->items())->pluck('id')->filter()->values();
        $replies = $ids->isEmpty()
            ? collect()
            : DB::table('event_posts as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.app_id', $appId)
                ->where('p.event_id', $event->id)
                ->whereIn('p.parent_id', $ids)
                ->where('p.status', 'published')
                ->select([
                    'p.id', 'p.parent_id', 'p.user_id', 'p.body', 'p.created_at',
                    'p.edited_at', 'u.first_name', 'u.last_name', 'u.avatar',
                ])
                ->when(
                    Schema::hasColumn('event_posts', 'file_id'),
                    fn ($query) => $query->addSelect('p.file_id')
                )
                ->selectSub(
                    fn ($q) => $q->from('event_post_likes as l')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('l.post_id', 'p.id')
                        ->where('l.app_id', $appId),
                    'likes_count'
                )
                ->orderBy('p.created_at')
                ->get()
                ->groupBy('parent_id');

        $allPostIds = $ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();
        $liked = collect();
        if ($user && $allPostIds->isNotEmpty()) {
            $liked = DB::table('event_post_likes')
                ->where('app_id', $appId)
                ->where('user_id', $user->id)
                ->whereIn('post_id', $allPostIds)
                ->pluck('post_id');
        }

        $fileIds = collect($posts->items())->pluck('file_id')
            ->merge($replies->flatten(1)->pluck('file_id'))
            ->filter()
            ->unique()
            ->values();
        $files = $fileIds->isEmpty()
            ? collect()
            : File::query()
                ->where('app_id', $appId)
                ->whereIn('id', $fileIds)
                ->where('visibility', 'public')
                ->where('status', 'active')
                ->get()
                ->keyBy('id');

        $posts->setCollection(
            collect($posts->items())->map(function ($post) use ($replies, $liked, $user, $files) {
                $post->is_liked = $user ? $liked->contains($post->id) : false;
                $post->media = ! empty($post->file_id) ? $this->serializeMedia($files->get($post->file_id)) : null;
                $post->replies = collect($replies->get($post->id, []))
                    ->map(function ($reply) use ($liked, $user, $files) {
                        $reply->is_liked = $user ? $liked->contains($reply->id) : false;
                        $reply->media = ! empty($reply->file_id) ? $this->serializeMedia($files->get($reply->file_id)) : null;
                        return $reply;
                    })
                    ->values();

                return $post;
            })
        );

        $ratingAggregate = DB::table('event_ratings')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->selectRaw(
                'ROUND(AVG(rating),1) average, COUNT(*) total'
                .(Schema::hasColumn('event_ratings', 'organization_rating') ? ', ROUND(AVG(organization_rating),1) organization_average' : '')
                .(Schema::hasColumn('event_ratings', 'service_rating') ? ', ROUND(AVG(service_rating),1) service_average' : '')
                .(Schema::hasColumn('event_ratings', 'music_rating') ? ', ROUND(AVG(music_rating),1) music_average' : '')
                .(Schema::hasColumn('event_ratings', 'value_rating') ? ', ROUND(AVG(value_rating),1) value_average' : '')
            )
            ->first();

        $mine = null;
        if ($user) {
            $mine = DB::table('event_ratings')
                ->where([
                    'app_id' => $appId,
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                ])
                ->first();
        }

        return response()->json([
            'posts' => $posts,
            'rating' => [
                'average' => $ratingAggregate?->average ? (float) $ratingAggregate->average : 0,
                'total' => (int) ($ratingAggregate?->total ?? 0),
                'mine' => $mine ? $this->serializeRating($mine) : null,
                'dimensions' => [
                    'organization' => isset($ratingAggregate->organization_average) ? (float) $ratingAggregate->organization_average : null,
                    'service' => isset($ratingAggregate->service_average) ? (float) $ratingAggregate->service_average : null,
                    'music' => isset($ratingAggregate->music_average) ? (float) $ratingAggregate->music_average : null,
                    'value' => isset($ratingAggregate->value_average) ? (float) $ratingAggregate->value_average : null,
                ],
            ],
            'reviews' => $this->reviewsFor($event, $user),
            'revive' => $this->revivePayload($event, $user, $access),
            'access' => $access,
        ]);
    }

    public function createPost(Request $request, int $eventId)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $data = $request->validate([
            'body' => 'required|string|min:2|max:3000',
            'parent_id' => 'nullable|integer|min:1',
        ]);

        if ($eventId === 0) {
            $parent = null;
            if ($parentId = $data['parent_id'] ?? null) {
                $parent = DB::table('event_posts')
                    ->where('id', $parentId)
                    ->where('app_id', $appId)
                    ->whereNull('event_id')
                    ->whereNull('parent_id')
                    ->where('status', 'published')
                    ->first();
                abort_unless($parent, 422, 'A publicação que você tentou comentar não está mais disponível.');
            }

            $id = DB::table('event_posts')->insertGetId([
                'app_id' => $appId,
                'event_id' => null,
                'user_id' => $user->id,
                'parent_id' => $data['parent_id'] ?? null,
                'body' => trim($data['body']),
                'status' => 'published',
                'is_pinned' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => $parent ? 'Comentário publicado.' : 'Publicação adicionada à timeline.',
                'post_id' => $id,
            ], 201);
        }

        $event = $this->publicEventById($eventId)->loadMissing('production');
        $this->assertCommunityInteractionAllowed($event, $user);

        $parent = null;
        if ($parentId = $data['parent_id'] ?? null) {
            $parent = DB::table('event_posts')
                ->where('id', $parentId)
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->whereNull('parent_id')
                ->where('status', 'published')
                ->first();
            abort_unless($parent, 422, 'A publicação que você tentou responder não está mais disponível.');
        }

        $id = DB::table('event_posts')->insertGetId([
            'app_id' => $appId,
            'event_id' => $event->id,
            'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body' => trim($data['body']),
            'status' => 'published',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifyCommunityActivity($event, $user, $id, $parent);
        $this->notifyMentions($event, $user, (string) $data['body']);

        return response()->json([
            'message' => $parent ? 'Comentário publicado.' : 'Publicação adicionada ao evento.',
            'post_id' => $id,
        ], 201);
    }

    public function uploadMedia(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        $user = $request->user();
        $access = $this->accessFor($event, $user);

        abort_unless($event->hasEnded(), 422, 'Os momentos do Reviva ficam disponíveis depois que o evento termina.');
        abort_unless($access['can_upload'], 403, 'Somente participantes verificados e a produção podem publicar momentos deste evento.');

        $data = $request->validate([
            'files' => 'required|array|min:1|max:12',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,webp|max:15360',
            'caption' => 'nullable|string|max:180',
        ]);

        $created = [];
        DB::transaction(function () use ($request, $data, $event, $user, $access, &$created) {
            $existingPrimary = File::query()
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'Event')
                ->where('entity_id', $event->id)
                ->where('group', 'event_revive')
                ->where('status', 'active')
                ->where('is_primary', true)
                ->exists();

            foreach ($request->file('files', []) as $index => $uploaded) {
                $file = File::storeOne(
                    $uploaded,
                    'Event',
                    (int) $event->id,
                    'image',
                    $this->context->id(),
                    (int) $user->id
                );
                $file = $this->mediaVariants->generateImageVariants($file);

                $meta = is_array($file->meta) ? $file->meta : [];
                $file->forceFill([
                    'group' => 'event_revive',
                    'position' => (int) (File::query()
                        ->where('app_id', $this->context->id())
                        ->where('entity_name', 'Event')
                        ->where('entity_id', $event->id)
                        ->where('group', 'event_revive')
                        ->max('position') ?? 0) + 1,
                    'is_primary' => $access['is_manager'] && ! $existingPrimary && $index === 0,
                    'meta' => array_merge($meta, [
                        'caption' => trim((string) ($data['caption'] ?? '')) ?: null,
                        'source' => $access['is_manager'] ? 'producer' : 'participant',
                        'verified_attendee' => $access['is_verified_attendee'],
                        'presence_confirmed' => $access['presence_confirmed'],
                    ]),
                ])->save();

                $postId = DB::table('event_posts')->insertGetId([
                    'app_id' => $this->context->id(),
                    'event_id' => $event->id,
                    'user_id' => $user->id,
                    'parent_id' => null,
                    'file_id' => $file->id,
                    'body' => trim((string) ($data['caption'] ?? ''))
                        ?: ($access['is_manager'] ? 'A produção adicionou um momento deste evento.' : 'Compartilhou um momento deste evento.'),
                    'status' => 'published',
                    'is_pinned' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created[] = [
                    'file' => $this->serializeMedia($file->fresh()),
                    'post_id' => $postId,
                ];
            }
        });

        if (! empty($created[0]['post_id'])) {
            $this->notifyCommunityActivity($event, $user, (int) $created[0]['post_id'], null);
            $this->notifyMentions($event, $user, (string) ($data['caption'] ?? ''));
        }

        return response()->json([
            'message' => count($created) === 1 ? 'Momento publicado.' : 'Momentos publicados.',
            'media' => $created,
        ], 201);
    }

    public function updateMedia(Request $request, int $eventId, int $fileId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        abort_unless($event->hasEnded(), 422, 'O Reviva só pode ser gerenciado depois do encerramento.');

        $file = $this->eventReviveFile($event, $fileId);
        $user = $request->user();
        $manager = $this->isManager($event, $user);
        abort_unless($manager || (int) $file->created_by === (int) $user->id, 403);

        $data = $request->validate([
            'caption' => 'sometimes|nullable|string|max:180',
            'position' => 'sometimes|integer|min:0|max:65535',
            'is_primary' => 'sometimes|boolean',
        ]);

        if (($data['is_primary'] ?? false) === true) {
            abort_unless($manager, 403, 'Somente a produção pode definir o destaque oficial.');
            File::query()
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'Event')
                ->where('entity_id', $event->id)
                ->where('group', 'event_revive')
                ->where('status', 'active')
                ->update(['is_primary' => false, 'updated_at' => now()]);
        }

        $meta = is_array($file->meta) ? $file->meta : [];
        if (array_key_exists('caption', $data)) {
            $meta['caption'] = trim((string) ($data['caption'] ?? '')) ?: null;
        }

        $file->fill([
            'position' => $data['position'] ?? $file->position,
            'is_primary' => array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $file->is_primary,
            'meta' => $meta,
            'updated_by' => $user->id,
        ])->save();

        if (array_key_exists('caption', $data)) {
            DB::table('event_posts')
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('file_id', $file->id)
                ->where('user_id', $user->id)
                ->update([
                    'body' => trim((string) ($data['caption'] ?? ''))
                        ?: ($manager ? 'A produção adicionou um momento deste evento.' : 'Compartilhou um momento deste evento.'),
                    'edited_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['message' => 'Momento atualizado.', 'file' => $this->serializeMedia($file->fresh())]);
    }

    public function deleteMedia(Request $request, int $eventId, int $fileId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        $file = $this->eventReviveFile($event, $fileId);
        $user = $request->user();
        $manager = $this->isManager($event, $user);

        abort_unless($manager || (int) $file->created_by === (int) $user->id, 403);

        DB::transaction(function () use ($file, $event, $user) {
            DB::table('event_posts')
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('file_id', $file->id)
                ->update(['status' => 'hidden', 'updated_at' => now()]);

            if ($file->storage !== 'external' && $file->path) {
                Storage::disk('public')->delete($file->path);
            }

            $file->forceFill([
                'status' => 'inactive',
                'updated_by' => $user->id,
                'updated_at' => now(),
            ])->save();
        });

        return response()->json(['message' => 'Momento removido.']);
    }

    public function reorderMedia(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        abort_unless($event->hasEnded(), 422, 'O Reviva só pode ser organizado depois do evento.');
        abort_unless($this->isManager($event, $request->user()), 403, 'Somente a produção pode reorganizar a galeria.');

        $data = $request->validate([
            'file_ids' => 'required|array|min:1|max:100',
            'file_ids.*' => 'required|integer|distinct|min:1',
        ]);

        $ids = collect($data['file_ids'])->map(fn ($id) => (int) $id)->values();
        $valid = File::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'Event')
            ->where('entity_id', $event->id)
            ->where('group', 'event_revive')
            ->where('status', 'active')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        abort_unless($valid->count() === $ids->count() && $valid->sort()->values()->all() === $ids->sort()->values()->all(), 422, 'A ordem contém imagens inválidas.');

        DB::transaction(function () use ($ids, $request) {
            foreach ($ids as $position => $fileId) {
                File::query()
                    ->where('app_id', $this->context->id())
                    ->where('id', $fileId)
                    ->update([
                        'position' => $position,
                        'updated_by' => $request->user()->id,
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json(['message' => 'Ordem da galeria atualizada.']);
    }

    public function saveRevivePreferences(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        $user = $request->user();

        abort_unless($event->hasEnded(), 422, 'As preferências do Reviva só existem para eventos encerrados.');
        abort_unless($this->hasValidPass($event, $user), 403, 'Somente participantes podem alterar estas preferências.');

        $data = $request->validate([
            'show_attendance' => 'sometimes|boolean',
            'notify_next' => 'sometimes|boolean',
        ]);

        DB::table('event_revive_preferences')->updateOrInsert(
            [
                'app_id' => $this->context->id(),
                'event_id' => $event->id,
                'user_id' => $user->id,
            ],
            [
                'show_attendance' => (bool) ($data['show_attendance'] ?? false),
                'notify_next' => (bool) ($data['notify_next'] ?? true),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'Preferências do Reviva atualizadas.']);
    }

    public function deletePost(Request $request, int $postId)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $post = DB::table('event_posts')->where('app_id', $appId)->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');

        $event = $post->event_id
            ? Event::with('production')->where('app_id', $appId)->find($post->event_id)
            : null;
        $can = (int) $post->user_id === (int) $user->id
            || $user->hasProfile('Administrador')
            || ($event?->production && (int) $event->production->user_id === (int) $user->id);
        abort_unless($can, 403, 'Você não tem permissão para remover esta publicação.');

        DB::table('event_posts')
            ->where('app_id', $appId)
            ->where(fn ($q) => $q->where('id', $postId)->orWhere('parent_id', $postId))
            ->update(['status' => 'hidden', 'updated_at' => now()]);

        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $user = $request->user();
        $post = $this->publishedPost($postId);
        $appId = $this->context->id();

        if ($post->event_id) {
            $event = $this->publicEventById((int) $post->event_id)->loadMissing('production');
            $this->assertCommunityInteractionAllowed($event, $user);
        }

        $already = DB::table('event_post_likes')
            ->where(['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id])
            ->exists();

        DB::table('event_post_likes')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        if (! $already && (int) $post->user_id !== (int) $user->id && $post->event_id) {
            $event = Event::where('app_id', $appId)->find($post->event_id);
            if ($event) {
                $this->safeNotify($appId, (int) $post->user_id, [
                    'type' => 'comment_like',
                    'title' => 'Curtiram seu momento',
                    'message' => (trim((string) $user->first_name) ?: 'Alguém').' curtiu o que você publicou em '.$event->title.'.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/'.$event->slug.'#reviva',
                    'data' => ['event_id' => $event->id, 'post_id' => $post->id, 'actor_id' => $user->id],
                ]);
            }
        }

        return response()->json(['message' => 'Publicação curtida.', 'liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        $post = $this->publishedPost($postId);
        if ($post->event_id) {
            $event = $this->publicEventById((int) $post->event_id)->loadMissing('production');
            $this->assertCommunityInteractionAllowed($event, $request->user());
        }

        DB::table('event_post_likes')
            ->where([
                'app_id' => $this->context->id(),
                'post_id' => $postId,
                'user_id' => $request->user()->id,
            ])
            ->delete();

        return response()->json(['message' => 'Curtida removida.', 'liked' => false]);
    }

    public function rate(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        $user = $request->user();

        abort_unless($event->hasEnded(), 422, 'A avaliação fica disponível depois que o evento termina.');
        abort_unless($this->hasValidPass($event, $user), 403, 'Somente participantes com ingresso ou cortesia válida podem avaliar este evento.');

        $rules = [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ];
        foreach (['organization_rating', 'service_rating', 'music_rating', 'value_rating'] as $field) {
            if (Schema::hasColumn('event_ratings', $field)) {
                $rules[$field] = 'nullable|integer|min:1|max:5';
            }
        }
        $data = $request->validate($rules);

        $attributes = [
            'rating' => (int) $data['rating'],
            'verified_attendee' => true,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('event_ratings', 'comment')) {
            $attributes['comment'] = trim((string) ($data['comment'] ?? '')) ?: null;
        }
        foreach (['organization_rating', 'service_rating', 'music_rating', 'value_rating'] as $field) {
            if (Schema::hasColumn('event_ratings', $field)) {
                $attributes[$field] = isset($data[$field]) ? (int) $data[$field] : null;
            }
        }

        $exists = DB::table('event_ratings')->where([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'user_id' => $user->id,
        ])->exists();
        if (! $exists) {
            $attributes['created_at'] = now();
        }

        DB::table('event_ratings')->updateOrInsert(
            [
                'app_id' => $this->context->id(),
                'event_id' => $event->id,
                'user_id' => $user->id,
            ],
            $attributes
        );

        return response()->json([
            'message' => 'Sua avaliação foi registrada.',
            'rating' => $attributes['rating'],
            'verified_attendee' => true,
        ]);
    }

    public function markRatingHelpful(Request $request, int $eventId, int $ratingUserId)
    {
        $event = $this->publicEventById($eventId);
        $user = $request->user();

        abort_unless($event->hasEnded(), 422);
        abort_unless($this->hasValidPass($event, $user), 403, 'Somente participantes podem interagir com avaliações.');
        abort_if((int) $ratingUserId === (int) $user->id, 422, 'Você não pode marcar sua própria avaliação como útil.');

        $exists = DB::table('event_ratings')->where([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'user_id' => $ratingUserId,
        ])->exists();
        abort_unless($exists, 404, 'Avaliação não encontrada.');

        DB::table('event_rating_helpful')->updateOrInsert(
            [
                'app_id' => $this->context->id(),
                'event_id' => $event->id,
                'rating_user_id' => $ratingUserId,
                'user_id' => $user->id,
            ],
            ['created_at' => now(), 'updated_at' => now()]
        );

        return response()->json(['message' => 'Avaliação marcada como útil.', 'helpful' => true]);
    }

    public function unmarkRatingHelpful(Request $request, int $eventId, int $ratingUserId)
    {
        $event = $this->publicEventById($eventId);
        abort_unless($event->hasEnded(), 422);
        abort_unless($this->hasValidPass($event, $request->user()), 403);

        DB::table('event_rating_helpful')->where([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'rating_user_id' => $ratingUserId,
            'user_id' => $request->user()->id,
        ])->delete();

        return response()->json(['message' => 'Marcação removida.', 'helpful' => false]);
    }

    public function respondToRating(Request $request, int $eventId, int $ratingUserId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        abort_unless($this->isManager($event, $request->user()), 403, 'Somente a produção pode responder avaliações.');

        $data = $request->validate(['response' => 'required|string|min:2|max:2000']);

        $updated = DB::table('event_ratings')->where([
            'app_id' => $this->context->id(),
            'event_id' => $event->id,
            'user_id' => $ratingUserId,
        ])->update([
            'producer_response' => trim($data['response']),
            'producer_responded_by' => $request->user()->id,
            'producer_responded_at' => now(),
            'updated_at' => now(),
        ]);

        abort_unless($updated, 404, 'Avaliação não encontrada.');

        $this->safeNotify($this->context->id(), $ratingUserId, [
            'type' => 'event_rating_response',
            'title' => 'A produção respondeu sua avaliação',
            'message' => 'A produção respondeu sua avaliação de '.$event->title.'.',
            'reference_type' => 'event',
            'reference_id' => $event->id,
            'reference_url' => '/event/'.$event->slug.'#reviva',
            'data' => ['event_id' => $event->id],
        ]);

        return response()->json(['message' => 'Resposta publicada.']);
    }

    public function trackReviveInteraction(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        abort_unless($event->hasEnded(), 422, 'O Reviva só está disponível para eventos encerrados.');

        $data = $request->validate([
            'type' => 'required|string|in:revive_view,revive_share,revive_moment_share,revive_next_click,revive_related_click,revive_follow,revive_review,revive_post,revive_reply,revive_reaction,revive_media_upload',
            'target_id' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array|max:20',
        ]);

        $user = $this->optionalUser($request);
        $type = (string) $data['type'];

        if ($type === 'revive_view') {
            $recent = Interaction::query()
                ->where('app_id', $this->context->id())
                ->where('entity_type', 'Event')
                ->where('entity_id', $event->id)
                ->where('interaction_type', 'revive_view')
                ->when(
                    $user,
                    fn ($q) => $q->where('user_id', $user->id),
                    fn ($q) => $q->whereNull('user_id')->where('session_key', substr(hash('sha256', (string) $request->ip().'|'.(string) $request->userAgent()), 0, 40))
                )
                ->where('created_at', '>=', now()->subMinutes(10))
                ->exists();

            if ($recent) {
                return response()->json(['tracked' => false, 'deduplicated' => true]);
            }
        }

        Interaction::register(
            $type,
            $event,
            $user,
            array_filter([
                'source_channel' => 'revive',
                'target_id' => isset($data['target_id']) ? (int) $data['target_id'] : null,
                'metadata' => $data['metadata'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'Reviva · '.str_replace('_', ' ', substr($type, 7))
        );

        return response()->json(['tracked' => true], 201);
    }

    public function report(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId);
        $data = $request->validate([
            'reason' => 'required|in:fraud,misleading,inappropriate,safety,cancelled,illegal,hate,harassment,spam,copyright,other',
            'details' => 'nullable|string|max:3000',
            'target_type' => 'sometimes|string|in:event,post,media,rating',
            'target_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $targetType = (string) ($data['target_type'] ?? 'event');
        $targetId = (int) ($data['target_id'] ?? $event->id);

        if ($targetType === 'event') {
            $targetId = (int) $event->id;
            DB::table('event_reports')->updateOrInsert(
                [
                    'app_id' => $this->context->id(),
                    'event_id' => $event->id,
                    'user_id' => $request->user()->id,
                ],
                [
                    'reason' => $data['reason'],
                    'details' => trim((string) ($data['details'] ?? '')) ?: null,
                    'status' => 'open',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'moderation_note' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return response()->json(['message' => 'Denúncia enviada para revisão.']);
        }

        abort_unless(Schema::hasTable('content_reports'), 503, 'A moderação de conteúdo está temporariamente indisponível.');
        $this->assertReportTargetBelongsToEvent($event, $targetType, $targetId);

        DB::table('content_reports')->updateOrInsert(
            [
                'app_id' => $this->context->id(),
                'entity_type' => 'Event',
                'entity_id' => $event->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'user_id' => $request->user()->id,
            ],
            [
                'reason' => $data['reason'],
                'details' => trim((string) ($data['details'] ?? '')) ?: null,
                'status' => 'open',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'moderation_note' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json(['message' => 'Conteúdo enviado para moderação.']);
    }

    public function moderationQueue(Request $request, int $eventId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        abort_unless($this->isManager($event, $request->user()), 403, 'Somente a produção pode acessar a moderação.');

        $reports = Schema::hasTable('content_reports')
            ? DB::table('content_reports as r')
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.app_id', $this->context->id())
                ->where('r.entity_type', 'Event')
                ->where('r.entity_id', $event->id)
                ->select([
                    'r.id', 'r.target_type', 'r.target_id', 'r.reason', 'r.details',
                    'r.status', 'r.moderation_note', 'r.created_at', 'r.reviewed_at',
                    'u.first_name', 'u.last_name', 'u.user_name',
                ])
                ->orderByRaw("CASE WHEN r.status = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('r.created_at')
                ->paginate(min(max((int) $request->input('per_page', 30), 1), 100))
            : collect();

        return response()->json(['reports' => $reports]);
    }

    public function moderateContentReport(Request $request, int $eventId, int $reportId)
    {
        $event = $this->publicEventById($eventId)->loadMissing('production');
        abort_unless($this->isManager($event, $request->user()), 403, 'Somente a produção pode moderar este conteúdo.');

        $data = $request->validate([
            'status' => 'required|in:reviewed,dismissed,actioned',
            'moderation_note' => 'nullable|string|max:2000',
            'hide_content' => 'sometimes|boolean',
        ]);

        $report = DB::table('content_reports')
            ->where('app_id', $this->context->id())
            ->where('entity_type', 'Event')
            ->where('entity_id', $event->id)
            ->where('id', $reportId)
            ->first();
        abort_unless($report, 404, 'Denúncia não encontrada.');

        if (($data['hide_content'] ?? false) === true) {
            if ($report->target_type === 'post') {
                DB::table('event_posts')
                    ->where('app_id', $this->context->id())
                    ->where('event_id', $event->id)
                    ->where('id', $report->target_id)
                    ->update(['status' => 'hidden', 'updated_at' => now()]);
            } elseif ($report->target_type === 'media') {
                File::query()
                    ->where('app_id', $this->context->id())
                    ->where('entity_name', 'Event')
                    ->where('entity_id', $event->id)
                    ->where('group', 'event_revive')
                    ->where('id', $report->target_id)
                    ->update(['status' => 'inactive', 'updated_by' => $request->user()->id]);
            }

            // Reviews remain immutable to producers. Negative feedback can be
            // reported and reviewed, but only platform moderation may remove it.
            abort_if(
                $report->target_type === 'rating' && ! $request->user()->hasProfile('Administrador'),
                403,
                'Avaliações não podem ser removidas pela produção.'
            );
        }

        DB::table('content_reports')->where('id', $report->id)->update([
            'status' => $data['status'],
            'moderation_note' => trim((string) ($data['moderation_note'] ?? '')) ?: null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Moderação atualizada.']);
    }

    private function assertReportTargetBelongsToEvent(Event $event, string $targetType, int $targetId): void
    {
        $exists = match ($targetType) {
            'post' => DB::table('event_posts')
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('id', $targetId)
                ->exists(),
            'media' => File::query()
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'Event')
                ->where('entity_id', $event->id)
                ->where('group', 'event_revive')
                ->where('id', $targetId)
                ->exists(),
            'rating' => DB::table('event_ratings')
                ->where('app_id', $this->context->id())
                ->where('event_id', $event->id)
                ->where('user_id', $targetId)
                ->exists(),
            default => false,
        };

        abort_unless($exists, 404, 'O conteúdo denunciado não pertence a este evento.');
    }

    private function revivePayload(Event $event, ?User $user, array $access): array
    {
        $isPast = $event->hasEnded();
        if (! $isPast) {
            return [
                'enabled' => false,
                'headline' => null,
                'gallery' => [],
                'attendance' => null,
                'next_event' => null,
                'achievements' => [],
                'preferences' => null,
                'metrics' => null,
            ];
        }

        $appId = $this->context->id();
        $gallery = File::query()
            ->where('app_id', $appId)
            ->where('entity_name', 'Event')
            ->where('entity_id', $event->id)
            ->where('group', 'event_revive')
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn (File $file) => $this->serializeMedia($file))
            ->values();

        $validPasses = EventPass::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES);

        $attendance = [
            'verified' => (clone $validPasses)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'checked_in' => (clone $validPasses)->whereNotNull('checked_in_at')->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'visible_people' => [],
        ];

        if (Schema::hasTable('event_revive_preferences')) {
            $attendance['visible_people'] = DB::table('event_revive_preferences as rp')
                ->join('users as u', 'u.id', '=', 'rp.user_id')
                ->where('rp.app_id', $appId)
                ->where('rp.event_id', $event->id)
                ->where('rp.show_attendance', true)
                ->whereExists(function ($q) use ($event) {
                    $q->selectRaw('1')
                        ->from('event_passes as ep')
                        ->whereColumn('ep.user_id', 'rp.user_id')
                        ->where('ep.event_id', $event->id)
                        ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES);
                })
                ->select(['u.id', 'u.first_name', 'u.last_name', 'u.user_name', 'u.avatar'])
                ->orderBy('u.first_name')
                ->limit(24)
                ->get();
        }

        $nextEvent = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $event->production_id)
            ->where('id', '!=', $event->id)
            ->publiclyVisible()
            ->where('start_date', '>', now())
            ->orderBy('start_date')
            ->first(['id', 'title', 'slug', 'image', 'start_date', 'end_date', 'city', 'uf', 'venue']);

        $related = Event::query()
            ->where('app_id', $appId)
            ->where('id', '!=', $event->id)
            ->publiclyVisible()
            ->where('start_date', '>', now())
            ->where(function ($q) use ($event) {
                $q->where('production_id', $event->production_id);

                if (trim((string) $event->category) !== '') {
                    $q->orWhere('category', $event->category);
                }
                if (trim((string) $event->city) !== '') {
                    $q->orWhere('city', $event->city);
                }
            })
            ->orderByRaw('CASE WHEN production_id = ? THEN 0 ELSE 1 END', [(int) $event->production_id])
            ->orderBy('start_date')
            ->limit(6)
            ->get(['id', 'production_id', 'title', 'slug', 'image', 'start_date', 'end_date', 'city', 'uf', 'venue', 'category']);

        $eventRating = DB::table('event_ratings')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->selectRaw('AVG(rating) average, COUNT(*) total')
            ->first();
        $publishedPosts = DB::table('event_posts')
            ->where('app_id', $appId)
            ->where('event_id', $event->id)
            ->where('status', 'published')
            ->count();
        $eventBadges = array_values(array_filter([
            ((float) ($eventRating?->average ?? 0) >= 4.5 && (int) ($eventRating?->total ?? 0) >= 5)
                ? ['key' => 'highly_rated', 'label' => 'Muito bem avaliado'] : null,
            ((int) $attendance['verified'] >= 100)
                ? ['key' => 'popular_event', 'label' => 'Evento popular'] : null,
            ($publishedPosts >= 20)
                ? ['key' => 'active_community', 'label' => 'Comunidade ativa'] : null,
        ]));

        $preferences = null;
        if ($user && Schema::hasTable('event_revive_preferences')) {
            $pref = DB::table('event_revive_preferences')->where([
                'app_id' => $appId,
                'event_id' => $event->id,
                'user_id' => $user->id,
            ])->first();
            $preferences = [
                'show_attendance' => (bool) ($pref?->show_attendance ?? false),
                'notify_next' => (bool) ($pref?->notify_next ?? true),
            ];
        }

        $achievements = [];
        if ($user && $access['is_verified_attendee']) {
            $attended = EventPass::query()
                ->where('user_id', $user->id)
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->whereHas('event', fn ($q) => $q->where('app_id', $appId)->where('end_date', '<=', now()))
                ->distinct('event_id')
                ->count('event_id');

            $sameProduction = EventPass::query()
                ->where('user_id', $user->id)
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->whereHas('event', fn ($q) => $q
                    ->where('app_id', $appId)
                    ->where('production_id', $event->production_id)
                    ->where('end_date', '<=', now()))
                ->distinct('event_id')
                ->count('event_id');

            $achievements = array_values(array_filter([
                $attended >= 1 ? ['key' => 'first_event', 'label' => 'Primeiro evento'] : null,
                $attended >= 5 ? ['key' => 'events_5', 'label' => '5 eventos'] : null,
                $attended >= 10 ? ['key' => 'events_10', 'label' => '10 eventos'] : null,
                $attended >= 20 ? ['key' => 'events_20', 'label' => '20 eventos'] : null,
                $sameProduction >= 3 ? ['key' => 'production_fan', 'label' => 'Fã desta produção'] : null,
            ]));
        }

        $metrics = null;
        if ($access['is_manager']) {
            $attributed = DB::table('commerce_orders')
                ->where('app_id', $appId)
                ->where('status', 'paid')
                ->where('metadata->source_event_id', (int) $event->id);

            $reviveInteractions = Interaction::query()
                ->where('app_id', $appId)
                ->where('entity_type', 'Event')
                ->where('entity_id', $event->id)
                ->where('interaction_type', 'like', 'revive_%');

            $uniqueVisitors = (clone $reviveInteractions)
                ->where('interaction_type', 'revive_view')
                ->whereNotNull('session_key')
                ->distinct()
                ->count('session_key');
            $attributedOrders = (clone $attributed)->count();

            $metrics = [
                'gallery_items' => $gallery->count(),
                'posts' => DB::table('event_posts')->where([
                    'app_id' => $appId,
                    'event_id' => $event->id,
                    'status' => 'published',
                ])->count(),
                'reviews' => DB::table('event_ratings')->where([
                    'app_id' => $appId,
                    'event_id' => $event->id,
                ])->count(),
                'views' => (clone $reviveInteractions)->where('interaction_type', 'revive_view')->count(),
                'unique_visitors' => $uniqueVisitors,
                'shares' => (clone $reviveInteractions)->whereIn('interaction_type', ['revive_share', 'revive_moment_share'])->count(),
                'next_event_clicks' => (clone $reviveInteractions)->where('interaction_type', 'revive_next_click')->count(),
                'related_event_clicks' => (clone $reviveInteractions)->where('interaction_type', 'revive_related_click')->count(),
                'followers_from_revive' => (clone $reviveInteractions)->where('interaction_type', 'revive_follow')->count(),
                'attributed_orders' => $attributedOrders,
                'attributed_gmv' => round((float) (clone $attributed)->sum('total'), 2),
                'post_event_conversion_rate' => $uniqueVisitors > 0
                    ? round(($attributedOrders / $uniqueVisitors) * 100, 2)
                    : 0,
            ];
        }

        return [
            'enabled' => true,
            'headline' => $access['is_verified_attendee'] || $access['is_manager']
                ? 'Só quem foi sabe.'
                : 'Veja o que você perdeu.',
            'gallery' => $gallery,
            'attendance' => $attendance,
            'next_event' => $nextEvent,
            'related_events' => $related,
            'event_badges' => $eventBadges,
            'achievements' => $achievements,
            'preferences' => $preferences,
            'metrics' => $metrics,
        ];
    }

    private function reviewsFor(Event $event, ?User $user): Collection
    {
        $appId = $this->context->id();
        $columns = [
            'r.user_id', 'r.rating', 'r.verified_attendee', 'r.created_at', 'r.updated_at',
            'u.first_name', 'u.last_name', 'u.user_name', 'u.avatar',
        ];

        foreach ([
            'comment',
            'organization_rating',
            'service_rating',
            'music_rating',
            'value_rating',
            'producer_response',
            'producer_responded_at',
        ] as $column) {
            if (Schema::hasColumn('event_ratings', $column)) {
                $columns[] = 'r.'.$column;
            }
        }

        $query = DB::table('event_ratings as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.app_id', $appId)
            ->where('r.event_id', $event->id)
            ->select($columns);

        $query->selectSub(
            fn ($q) => $q->from('event_passes as ep')
                ->selectRaw('COUNT(*)')
                ->whereColumn('ep.user_id', 'r.user_id')
                ->where('ep.event_id', $event->id)
                ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES)
                ->whereNotNull('ep.checked_in_at'),
            'presence_confirmed'
        );

        if (Schema::hasTable('event_rating_helpful')) {
            $query->selectSub(
                fn ($q) => $q->from('event_rating_helpful as h')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('h.rating_user_id', 'r.user_id')
                    ->where('h.app_id', $appId)
                    ->where('h.event_id', $event->id),
                'helpful_count'
            );
        }

        $reviews = $query->orderByDesc('helpful_count')->orderByDesc('r.updated_at')->limit(30)->get();

        $mineHelpful = collect();
        if ($user && Schema::hasTable('event_rating_helpful')) {
            $mineHelpful = DB::table('event_rating_helpful')
                ->where('app_id', $appId)
                ->where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->pluck('rating_user_id');
        }

        return $reviews->map(function ($review) use ($mineHelpful) {
            $review->helpful_count = (int) ($review->helpful_count ?? 0);
            $review->presence_confirmed = (int) ($review->presence_confirmed ?? 0) > 0;
            $review->is_helpful = $mineHelpful->contains($review->user_id);
            return $review;
        });
    }

    private function accessFor(Event $event, ?User $user): array
    {
        $isPast = $event->hasEnded();
        $verified = $user ? $this->hasValidPass($event, $user) : false;
        $checkedIn = $user ? $this->hasCheckedIn($event, $user) : false;
        $manager = $user ? $this->isManager($event, $user) : false;

        return [
            'is_past' => $isPast,
            'is_verified_attendee' => $verified,
            'presence_confirmed' => $checkedIn,
            'is_manager' => $manager,
            'can_interact' => (bool) $user && (! $isPast || $verified || $manager),
            'can_rate' => (bool) $user && $isPast && $verified,
            'can_upload' => (bool) $user && $isPast && ($verified || $manager),
            'lock_reason' => ! $isPast
                ? null
                : ((! $user)
                    ? 'login_required'
                    : (($verified || $manager) ? null : 'verified_attendee_required')),
        ];
    }

    private function assertCommunityInteractionAllowed(Event $event, User $user): void
    {
        if (! $event->hasEnded()) {
            return;
        }

        abort_unless(
            $this->hasValidPass($event, $user) || $this->isManager($event, $user),
            403,
            'Só quem participou deste evento pode interagir no Reviva.'
        );
    }

    private function hasValidPass(Event $event, User $user): bool
    {
        return EventPass::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->exists();
    }

    private function hasCheckedIn(Event $event, User $user): bool
    {
        return EventPass::query()
            ->where('event_id', $event->id)
            ->where('user_id', $user->id)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->whereNotNull('checked_in_at')
            ->exists();
    }

    private function isManager(Event $event, User $user): bool
    {
        if ($user->hasProfile('Administrador')) {
            return true;
        }

        $event->loadMissing('production');
        return $event->production && (int) $event->production->user_id === (int) $user->id;
    }

    private function eventReviveFile(Event $event, int $fileId): File
    {
        return File::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'Event')
            ->where('entity_id', $event->id)
            ->where('group', 'event_revive')
            ->where('id', $fileId)
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function serializeMedia(?File $file): ?array
    {
        if (! $file) {
            return null;
        }

        $meta = is_array($file->meta) ? $file->meta : [];

        return [
            'id' => $file->id,
            'url' => $file->public_url ?: ($file->path ? Storage::disk($file->storage ?: 'public')->url($file->path) : null),
            'caption' => $meta['caption'] ?? null,
            'variants' => $file->variants ?: [],
            'source' => $meta['source'] ?? null,
            'verified_attendee' => (bool) ($meta['verified_attendee'] ?? false),
            'presence_confirmed' => (bool) ($meta['presence_confirmed'] ?? false),
            'position' => (int) ($file->position ?? 0),
            'is_primary' => (bool) $file->is_primary,
            'created_by' => $file->created_by,
            'created_at' => $file->created_at,
        ];
    }

    private function serializeRating(object $rating): array
    {
        return [
            'rating' => (int) $rating->rating,
            'comment' => $rating->comment ?? null,
            'organization_rating' => isset($rating->organization_rating) ? (int) $rating->organization_rating : null,
            'service_rating' => isset($rating->service_rating) ? (int) $rating->service_rating : null,
            'music_rating' => isset($rating->music_rating) ? (int) $rating->music_rating : null,
            'value_rating' => isset($rating->value_rating) ? (int) $rating->value_rating : null,
            'verified_attendee' => (bool) ($rating->verified_attendee ?? false),
            'producer_response' => $rating->producer_response ?? null,
            'producer_responded_at' => $rating->producer_responded_at ?? null,
        ];
    }

    private function notifyCommunityActivity(Event $event, User $actor, int $postId, ?object $parent): void
    {
        $appId = $this->context->id();
        $parentAuthor = $parent ? (int) $parent->user_id : null;
        $attendees = EventPass::where('event_id', $event->id)
            ->whereNotNull('user_id')
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $actor->id || ($parentAuthor && $id === $parentAuthor))
            ->values();

        $name = trim((string) $actor->first_name) ?: 'Alguém';
        $payload = [
            'type' => $parent ? 'event_reply' : 'event_comment',
            'title' => $event->hasEnded() ? 'Novo momento no Reviva' : ($parent ? 'Nova resposta no evento' : 'Novo comentário no evento'),
            'message' => $name.($parent ? ' respondeu uma conversa em ' : ' publicou na conversa de ').$event->title.'.',
            'reference_type' => 'event',
            'reference_id' => $event->id,
            'reference_url' => '/event/'.$event->slug.($event->hasEnded() ? '#reviva' : '#comunidade'),
            'data' => ['event_id' => $event->id, 'post_id' => $postId, 'actor_id' => $actor->id],
        ];

        try {
            $this->notifications->sendToUsers($appId, $attendees, $payload, (int) $actor->id);
        } catch (Throwable $e) {
            report($e);
        }

        if ($parentAuthor && $parentAuthor !== (int) $actor->id) {
            $this->safeNotify($appId, $parentAuthor, $payload);
        }
    }

    private function notifyMentions(Event $event, User $actor, string $text): void
    {
        if (! preg_match_all('/@([A-Za-z0-9._-]{2,50})/u', $text, $matches)) {
            return;
        }

        $usernames = collect($matches[1] ?? [])
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->take(10)
            ->values();

        if ($usernames->isEmpty()) {
            return;
        }

        $mentioned = User::query()
            ->whereIn(DB::raw('LOWER(user_name)'), $usernames->all())
            ->where('id', '!=', $actor->id)
            ->get(['id', 'user_name']);

        if ($event->hasEnded()) {
            $participantIds = EventPass::query()
                ->where('event_id', $event->id)
                ->whereNotIn('status', self::INVALID_PASS_STATUSES)
                ->whereIn('user_id', $mentioned->pluck('id'))
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $mentioned = $mentioned->filter(
                fn (User $candidate) => in_array((int) $candidate->id, $participantIds, true)
            );
        }

        foreach ($mentioned as $candidate) {
            $this->safeNotify($this->context->id(), (int) $candidate->id, [
                'type' => 'event_mention',
                'title' => 'Você foi mencionado',
                'message' => (trim((string) $actor->first_name) ?: 'Alguém').' mencionou você em '.$event->title.'.',
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/event/'.$event->slug.($event->hasEnded() ? '#reviva' : '#comunidade'),
                'data' => ['event_id' => $event->id, 'actor_id' => $actor->id],
            ]);
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
        $post = DB::table('event_posts')
            ->where('app_id', $this->context->id())
            ->where('id', $id)
            ->where('status', 'published')
            ->first();
        abort_unless($post, 404, 'Publicação não encontrada.');

        return $post;
    }

    private function publicEventBySlug(string $slug): Event
    {
        return Event::where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();
    }

    private function publicEventById(int $id): Event
    {
        return Event::where('app_id', $this->context->id())
            ->whereKey($id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($q) => $q->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
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
