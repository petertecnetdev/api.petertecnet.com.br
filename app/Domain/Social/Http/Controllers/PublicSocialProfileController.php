<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EventPass;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;

final class PublicSocialProfileController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function show(int $userId)
    {
        $user = User::query()->findOrFail($userId);
        $appId = $this->context->id();
        $posts = $this->posts($userId, $appId);
        $tickets = $this->tickets($userId, $appId);

        return response()->json([
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'avatar' => $user->avatar,
                'city' => $user->city,
                'uf' => $user->uf,
                'about' => $user->about,
                'favorite_artist' => $user->favorite_artist,
                'favorite_genre' => $user->favorite_genre,
            ],
            'stats' => [
                'tickets' => count($tickets),
                'posts' => $this->postsCount($userId, $appId),
                'following_artists' => DB::table('follows')->where(['app_id' => $appId, 'user_id' => $userId, 'target_type' => 'artist'])->count(),
                'following_productions' => DB::table('follows')->where(['app_id' => $appId, 'user_id' => $userId, 'target_type' => 'production'])->count(),
            ],
            'posts' => $posts,
            'public_tickets' => $tickets,
        ]);
    }

    private function posts(int $userId, int $appId): array
    {
        $social = DB::table('social_posts as p')
            ->leftJoin('productions as o', function ($join) use ($appId) {
                $join->on('o.id', '=', 'p.scope_id')->where('o.app_id', '=', $appId);
            })
            ->where('p.app_id', $appId)
            ->where('p.user_id', $userId)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->where(function ($q) {
                $q->where('p.scope_type', 'global')
                    ->orWhere(function ($inner) {
                        $inner->where('p.scope_type', 'production')->where('o.is_published', true)->where('o.is_cancelled', false);
                    });
            })
            ->select(['p.id','p.body','p.created_at','p.scope_type','p.scope_id','o.name as target_title','o.slug as target_slug'])
            ->selectSub(fn ($q) => $q->from('social_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('social_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published'), 'comments_count')
            ->selectSub(fn ($q) => $q->from('social_post_views as v')->selectRaw('COUNT(*)')->whereColumn('v.post_id', 'p.id')->where('v.source_type', 'social')->where('v.app_id', $appId), 'views_count')
            ->latest('p.created_at')
            ->limit(60)
            ->get()
            ->map(fn ($post) => [
                'id' => (int) $post->id,
                'source_type' => $post->scope_type === 'production' ? 'production' : 'feed',
                'body' => $post->body,
                'created_at' => $post->created_at,
                'target_title' => $post->target_title,
                'target_slug' => $post->target_slug,
                'likes_count' => (int) $post->likes_count,
                'comments_count' => (int) $post->comments_count,
                'views_count' => (int) $post->views_count,
            ]);

        $event = DB::table('event_posts as p')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->where('p.app_id', $appId)
            ->where('p.user_id', $userId)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->where('e.app_id', $appId)
            ->where('e.is_published', true)
            ->where('e.is_cancelled', false)
            ->where(fn ($q) => $q->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->select(['p.id','p.body','p.created_at','e.title as target_title','e.slug as target_slug'])
            ->selectSub(fn ($q) => $q->from('event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('event_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published'), 'comments_count')
            ->selectSub(fn ($q) => $q->from('social_post_views as v')->selectRaw('COUNT(*)')->whereColumn('v.post_id', 'p.id')->where('v.source_type', 'event')->where('v.app_id', $appId), 'views_count')
            ->latest('p.created_at')
            ->limit(60)
            ->get()
            ->map(fn ($post) => [
                'id' => (int) $post->id,
                'source_type' => 'event',
                'body' => $post->body,
                'created_at' => $post->created_at,
                'target_title' => $post->target_title,
                'target_slug' => $post->target_slug,
                'likes_count' => (int) $post->likes_count,
                'comments_count' => (int) $post->comments_count,
                'views_count' => (int) $post->views_count,
            ]);

        return $social->concat($event)
            ->sortByDesc('created_at')
            ->take(60)
            ->values()
            ->all();
    }

    private function tickets(int $userId, int $appId): array
    {
        return EventPass::query()
            ->where('user_id', $userId)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId)->where('is_published', true)->where(fn ($inner) => $inner->where('is_private', false)->orWhereNull('is_private')))
            ->with(['ticket:id,event_id,name,type,ticket_type', 'event:id,title,slug,start_date,end_date,city,uf,image,is_cancelled'])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (EventPass $pass) => [
                'event' => $pass->event ? [
                    'title' => $pass->event->title,
                    'slug' => $pass->event->slug,
                    'start_date' => $pass->event->start_date,
                    'end_date' => $pass->event->end_date,
                    'city' => $pass->event->city,
                    'uf' => $pass->event->uf,
                    'image' => $pass->event->image,
                    'is_cancelled' => (bool) $pass->event->is_cancelled,
                ] : null,
                'ticket' => $pass->ticket ? [
                    'name' => $pass->ticket->name,
                    'type' => $pass->ticket->type,
                    'ticket_type' => $pass->ticket->ticket_type,
                ] : null,
                'status' => $pass->status,
                'checked_in' => ! empty($pass->checked_in_at),
            ])
            ->values()
            ->all();
    }

    private function postsCount(int $userId, int $appId): int
    {
        $social = DB::table('social_posts')->where(['app_id' => $appId, 'user_id' => $userId, 'status' => 'published'])->whereNull('parent_id')->count();
        $event = DB::table('event_posts')->where(['app_id' => $appId, 'user_id' => $userId, 'status' => 'published'])->whereNull('parent_id')->count();
        return $social + $event;
    }
}
