<?php

namespace App\Domain\Social\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ParticipantSocialService
{
    private const DEFAULT_SOCIAL_SETTINGS = [
        'discoverable' => true,
        'show_city' => true,
        'show_interests' => true,
        'show_event_interests' => true,
        'allow_follows' => true,
    ];

    public function index(int $appId, User $viewer, array $data): array
    {
        $perPage = (int) ($data['per_page'] ?? 24);
        $followingIds = $this->followingParticipantIds((int) $viewer->id, $appId);

        $query = User::query()
            ->select(['users.id', 'users.user_name', 'users.first_name', 'users.last_name', 'users.avatar', 'users.city', 'users.uf', 'users.about'])
            ->join('application_user as au', 'au.user_id', '=', 'users.id')
            ->leftJoin('user_social_preferences as usp', function ($join) use ($appId) {
                $join->on('usp.user_id', '=', 'users.id')->where('usp.app_id', '=', $appId);
            })
            ->where('au.application_id', $appId)
            ->where('au.status', 'active')
            ->where('users.id', '<>', $viewer->id)
            ->where(function ($visibility) use ($followingIds) {
                $visibility->whereNull('usp.id')->orWhere('usp.discoverable', true);
                if ($followingIds->isNotEmpty()) $visibility->orWhereIn('users.id', $followingIds);
            })
            ->distinct();

        if ($q = trim((string) ($data['q'] ?? ''))) {
            $query->where(function ($search) use ($q) {
                $search->where('users.first_name', 'like', "%{$q}%")
                    ->orWhere('users.last_name', 'like', "%{$q}%")
                    ->orWhere('users.user_name', 'like', "%{$q}%")
                    ->orWhere('users.about', 'like', "%{$q}%");
            });
        }

        if (! empty($data['city'])) {
            $query->where('users.city', $data['city'])
                ->where(fn ($privacy) => $privacy->whereNull('usp.id')->orWhere('usp.show_city', true));
        }

        if (! empty($data['uf'])) {
            $query->where('users.uf', strtoupper($data['uf']))
                ->where(fn ($privacy) => $privacy->whereNull('usp.id')->orWhere('usp.show_city', true));
        }

        if ($interest = trim((string) ($data['interest'] ?? ''))) {
            $candidateIds = DB::table('application_user_preferences as aup')
                ->leftJoin('user_social_preferences as pref', function ($join) use ($appId) {
                    $join->on('pref.user_id', '=', 'aup.user_id')->where('pref.app_id', '=', $appId);
                })
                ->where('aup.app_id', $appId)
                ->where(fn ($privacy) => $privacy->whereNull('pref.id')->orWhere('pref.show_interests', true))
                ->where('aup.interests', 'like', '%'.$interest.'%')
                ->pluck('aup.user_id');
            $query->whereIn('users.id', $candidateIds);
        }

        $participants = $query->orderBy('users.first_name')->orderBy('users.id')->paginate($perPage);
        $participants->setCollection(
            $this->decorateParticipants($participants->getCollection(), (int) $viewer->id, $appId)
                ->sortByDesc('affinity_score')->values()
        );

        return [
            'participants' => $participants,
            'viewer_context' => [
                'interests' => $this->interestsFor((int) $viewer->id, $appId),
                'city' => $viewer->city,
                'uf' => $viewer->uf,
                'social_settings' => $this->socialSettings((int) $viewer->id, $appId),
            ],
        ];
    }

    public function show(int $appId, User $viewer, int $participantId): array
    {
        $participant = $this->participantInApp($participantId, $appId);
        $settings = $this->socialSettings((int) $participant->id, $appId);
        $isFollowing = $this->isFollowing((int) $viewer->id, (int) $participant->id, $appId);
        $isFollowingViewer = $this->isFollowing((int) $participant->id, (int) $viewer->id, $appId);

        $participantInterests = $settings['show_interests'] ? $this->interestsFor((int) $participant->id, $appId) : [];
        $viewerInterests = $this->interestsFor((int) $viewer->id, $appId);
        $sharedInterests = $this->sharedInterests($viewerInterests, $participantInterests);
        $viewerEventIds = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $viewer->id, 'is_interested' => true])->pluck('event_id');

        $participantEventIds = collect();
        if ($settings['show_event_interests']) {
            $participantEventIds = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $participant->id, 'is_interested' => true])->pluck('event_id');
        }

        $sharedEventIds = $participantEventIds->intersect($viewerEventIds)->map(fn ($id) => (int) $id)->values();
        $interestedEvents = collect();
        if ($settings['show_event_interests'] && $participantEventIds->isNotEmpty()) {
            $interestedEvents = Event::query()
                ->where('app_id', $appId)
                ->whereIn('id', $participantEventIds)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
                ->where('end_date', '>', now())
                ->with('production:id,app_id,name,slug,logo')
                ->orderBy('start_date')->limit(18)->get();
        }

        $followers = DB::table('follows')->where(['app_id' => $appId, 'target_type' => 'user', 'target_id' => $participant->id])->count();
        $following = DB::table('follows')->where(['app_id' => $appId, 'user_id' => $participant->id, 'target_type' => 'user'])->count();
        $mutualFollowingCount = $this->mutualFollowingCount((int) $viewer->id, (int) $participant->id, $appId);
        $sameCity = $settings['show_city'] && $this->sameCity($viewer, $participant);
        $score = $this->affinityScore(count($sharedInterests), $sharedEventIds->count(), $sameCity, $mutualFollowingCount, $isFollowingViewer);

        return [
            'participant' => $this->safeProfile($participant, $settings),
            'interests' => $participantInterests,
            'shared_interests' => $sharedInterests,
            'interested_events' => $interestedEvents,
            'shared_event_ids' => $sharedEventIds,
            'stats' => [
                'followers' => $followers,
                'following_participants' => $following,
                'interested_events' => $participantEventIds->count(),
                'shared_interested_events' => $sharedEventIds->count(),
                'mutual_following' => $mutualFollowingCount,
            ],
            'is_following' => $isFollowing,
            'is_following_viewer' => $isFollowingViewer,
            'is_connection' => $isFollowing && $isFollowingViewer,
            'can_follow' => $settings['allow_follows'] || $isFollowing,
            'visibility' => [
                'show_city' => $settings['show_city'],
                'show_interests' => $settings['show_interests'],
                'show_event_interests' => $settings['show_event_interests'],
            ],
            'affinity_score' => $score,
            'affinity_reasons' => $this->affinityReasons(count($sharedInterests), $sharedEventIds->count(), $sameCity, $mutualFollowingCount, $isFollowingViewer),
        ];
    }

    public function activity(int $appId, int $viewerId, int $perPage): array
    {
        $followedParticipantIds = $this->followingParticipantIds($viewerId, $appId);
        if ($followedParticipantIds->isEmpty()) {
            return ['activity' => new LengthAwarePaginator([], 0, $perPage)];
        }

        $activity = DB::table('event_engagements as ee')
            ->join('users as u', 'u.id', '=', 'ee.user_id')
            ->join('application_user as au', function ($join) use ($appId) {
                $join->on('au.user_id', '=', 'u.id')->where('au.application_id', '=', $appId)->where('au.status', '=', 'active');
            })
            ->leftJoin('user_social_preferences as usp', function ($join) use ($appId) {
                $join->on('usp.user_id', '=', 'u.id')->where('usp.app_id', '=', $appId);
            })
            ->join('events as e', 'e.id', '=', 'ee.event_id')
            ->leftJoin('productions as p', 'p.id', '=', 'e.production_id')
            ->where('ee.app_id', $appId)->where('e.app_id', $appId)
            ->whereIn('ee.user_id', $followedParticipantIds)->where('ee.is_interested', true)
            ->where(fn ($privacy) => $privacy->whereNull('usp.id')->orWhere('usp.show_event_interests', true))
            ->where('e.is_published', true)->where('e.is_cancelled', false)
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->where('e.end_date', '>', now())->orderByDesc('ee.updated_at')
            ->paginate($perPage, [
                'ee.updated_at as activity_at', 'u.id as participant_id', 'u.user_name', 'u.first_name', 'u.last_name', 'u.avatar',
                'e.id as event_id', 'e.title as event_title', 'e.slug as event_slug', 'e.image as event_image', 'e.start_date', 'e.city', 'e.uf',
                'p.name as production_name',
            ]);

        return ['activity' => $activity];
    }

    public function settings(int $userId, int $appId): array
    {
        return $this->socialSettings($userId, $appId);
    }

    public function updateSettings(int $userId, int $appId, array $data): array
    {
        DB::table('user_social_preferences')->upsert([
            [
                'app_id' => $appId,
                'user_id' => $userId,
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['app_id', 'user_id'], ['discoverable', 'show_city', 'show_interests', 'show_event_interests', 'allow_follows', 'updated_at']);

        return $this->socialSettings($userId, $appId);
    }

    public function follow(int $appId, User $viewer, int $participantId): array
    {
        $participant = $this->participantInApp($participantId, $appId);
        abort_if((int) $participant->id === (int) $viewer->id, 422, 'Você não pode seguir o próprio perfil.');

        $key = ['app_id' => $appId, 'user_id' => $viewer->id, 'target_type' => 'user', 'target_id' => $participant->id];
        $alreadyFollowing = DB::table('follows')->where($key)->exists();
        $settings = $this->socialSettings((int) $participant->id, $appId);
        abort_if(! $alreadyFollowing && ! $settings['allow_follows'], 403, 'Este participante não está aceitando novos seguidores agora.');

        if ($alreadyFollowing) DB::table('follows')->where($key)->update(['updated_at' => now()]);
        else DB::table('follows')->insert($key + ['created_at' => now(), 'updated_at' => now()]);

        if (! $alreadyFollowing) {
            $actorName = trim(implode(' ', array_filter([$viewer->first_name, $viewer->last_name]))) ?: ($viewer->user_name ?: 'Alguém');
            try {
                app(AppNotificationService::class)->sendToUser($appId, (int) $participant->id, [
                    'type' => 'participant_follow',
                    'title' => 'Novo seguidor na Cutinapp',
                    'message' => Str::limit("{$actorName} começou a seguir seu perfil de participante.", 500),
                    'reference_type' => 'user',
                    'reference_id' => (int) $viewer->id,
                    'reference_url' => '/participantes/'.$viewer->id,
                    'data' => ['actor_user_id' => (int) $viewer->id],
                ]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $followsViewer = $this->isFollowing((int) $participant->id, (int) $viewer->id, $appId);
        return [
            'message' => $followsViewer ? 'Vocês agora são uma conexão na Cutinapp.' : 'Agora você segue este participante.',
            'following' => true,
            'is_connection' => $followsViewer,
        ];
    }

    public function unfollow(int $appId, int $viewerId, int $participantId): array
    {
        $participant = $this->participantInApp($participantId, $appId);
        DB::table('follows')->where(['app_id' => $appId, 'user_id' => $viewerId, 'target_type' => 'user', 'target_id' => $participant->id])->delete();
        return ['message' => 'Você deixou de seguir este participante.', 'following' => false, 'is_connection' => false];
    }

    private function decorateParticipants(Collection $participants, int $viewerId, int $appId): Collection
    {
        if ($participants->isEmpty()) return $participants;

        $ids = $participants->pluck('id')->map(fn ($id) => (int) $id)->values();
        $preferences = DB::table('application_user_preferences')->where('app_id', $appId)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $socialSettings = DB::table('user_social_preferences')->where('app_id', $appId)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $viewerInterests = $this->interestsFor($viewerId, $appId);
        $viewerEventIds = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $viewerId, 'is_interested' => true])->pluck('event_id');
        $viewerFollowingIds = $this->followingParticipantIds($viewerId, $appId);
        $following = $viewerFollowingIds->map(fn ($id) => (int) $id)->flip();
        $followersBack = DB::table('follows')->where(['app_id' => $appId, 'target_type' => 'user', 'target_id' => $viewerId])->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id) => (int) $id)->flip();
        $followerCounts = DB::table('follows')->select('target_id', DB::raw('COUNT(*) as total'))->where(['app_id' => $appId, 'target_type' => 'user'])->whereIn('target_id', $ids)->groupBy('target_id')->pluck('total', 'target_id');

        $sharedEventCounts = collect();
        if ($viewerEventIds->isNotEmpty()) {
            $sharedEventCounts = DB::table('event_engagements')->select('user_id', DB::raw('COUNT(*) as total'))->where('app_id', $appId)->where('is_interested', true)->whereIn('user_id', $ids)->whereIn('event_id', $viewerEventIds)->groupBy('user_id')->pluck('total', 'user_id');
        }

        $mutualFollowingCounts = collect();
        if ($viewerFollowingIds->isNotEmpty()) {
            $mutualFollowingCounts = DB::table('follows')->select('user_id', DB::raw('COUNT(*) as total'))->where('app_id', $appId)->where('target_type', 'user')->whereIn('user_id', $ids)->whereIn('target_id', $viewerFollowingIds)->groupBy('user_id')->pluck('total', 'user_id');
        }

        $viewer = User::query()->select(['id', 'city', 'uf'])->find($viewerId);

        return $participants->map(function (User $participant) use ($preferences, $socialSettings, $viewerInterests, $following, $followersBack, $followerCounts, $sharedEventCounts, $mutualFollowingCounts, $viewer) {
            $settings = $this->normalizeSocialSettings($socialSettings->get($participant->id));
            $interests = $settings['show_interests'] ? $this->decodeInterests($preferences->get($participant->id)?->interests ?? null) : [];
            $sharedInterests = $this->sharedInterests($viewerInterests, $interests);
            $sharedEvents = $settings['show_event_interests'] ? (int) ($sharedEventCounts->get($participant->id) ?? 0) : 0;
            $mutualFollowing = (int) ($mutualFollowingCounts->get($participant->id) ?? 0);
            $followsViewer = $followersBack->has((int) $participant->id);
            $viewerFollows = $following->has((int) $participant->id);
            $sameCity = $settings['show_city'] && $viewer ? $this->sameCity($viewer, $participant) : false;

            return [
                ...$this->safeProfile($participant, $settings),
                'interests' => $interests,
                'shared_interests' => $sharedInterests,
                'shared_interested_events' => $sharedEvents,
                'mutual_following' => $mutualFollowing,
                'followers_count' => (int) ($followerCounts->get($participant->id) ?? 0),
                'is_following' => $viewerFollows,
                'is_following_viewer' => $followsViewer,
                'is_connection' => $viewerFollows && $followsViewer,
                'can_follow' => $settings['allow_follows'] || $viewerFollows,
                'affinity_score' => $this->affinityScore(count($sharedInterests), $sharedEvents, $sameCity, $mutualFollowing, $followsViewer),
                'affinity_reasons' => $this->affinityReasons(count($sharedInterests), $sharedEvents, $sameCity, $mutualFollowing, $followsViewer),
            ];
        });
    }

    private function participantInApp(int $participantId, int $appId): User
    {
        return User::query()->select(['users.id', 'users.user_name', 'users.first_name', 'users.last_name', 'users.avatar', 'users.city', 'users.uf', 'users.about'])
            ->join('application_user as au', 'au.user_id', '=', 'users.id')
            ->where('au.application_id', $appId)->where('au.status', 'active')->where('users.id', $participantId)->firstOrFail();
    }

    private function safeProfile(User $participant, array $settings): array
    {
        return [
            'id' => (int) $participant->id,
            'user_name' => $participant->user_name,
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'avatar' => $participant->avatar,
            'city' => $settings['show_city'] ? $participant->city : null,
            'uf' => $settings['show_city'] ? $participant->uf : null,
            'about' => $participant->about,
        ];
    }

    private function interestsFor(int $userId, int $appId): array
    {
        return $this->decodeInterests(DB::table('application_user_preferences')->where(['app_id' => $appId, 'user_id' => $userId])->value('interests'));
    }

    private function decodeInterests(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        return collect(is_array($value) ? $value : [])->filter(fn ($interest) => is_string($interest) && trim($interest) !== '')->map(fn ($interest) => trim($interest))->unique(fn ($interest) => mb_strtolower($interest))->values()->take(50)->all();
    }

    private function sharedInterests(array $viewerInterests, array $participantInterests): array
    {
        $participantIndex = collect($participantInterests)->mapWithKeys(fn ($interest) => [mb_strtolower($interest) => $interest]);
        return collect($viewerInterests)->map(fn ($interest) => $participantIndex->get(mb_strtolower($interest)))->filter()->values()->all();
    }

    private function affinityScore(int $sharedInterests, int $sharedEvents, bool $sameCity, int $mutualFollowing, bool $followsViewer): int
    {
        return min(100, ($sharedInterests * 18) + ($sharedEvents * 12) + ($sameCity ? 10 : 0) + (min(3, $mutualFollowing) * 7) + ($followsViewer ? 15 : 0));
    }

    private function affinityReasons(int $sharedInterests, int $sharedEvents, bool $sameCity, int $mutualFollowing, bool $followsViewer): array
    {
        $reasons = [];
        if ($followsViewer) $reasons[] = 'Também segue você';
        if ($sharedInterests > 0) $reasons[] = $sharedInterests.' '.($sharedInterests === 1 ? 'interesse em comum' : 'interesses em comum');
        if ($sharedEvents > 0) $reasons[] = $sharedEvents.' '.($sharedEvents === 1 ? 'evento em comum' : 'eventos em comum');
        if ($mutualFollowing > 0) $reasons[] = $mutualFollowing.' '.($mutualFollowing === 1 ? 'conexão em comum' : 'conexões em comum');
        if ($sameCity) $reasons[] = 'Vocês estão na mesma cidade';
        return $reasons;
    }

    private function sameCity(User $left, User $right): bool
    {
        if (! $left->city || ! $right->city) return false;
        return mb_strtolower(trim((string) $left->city)) === mb_strtolower(trim((string) $right->city))
            && strtoupper(trim((string) $left->uf)) === strtoupper(trim((string) $right->uf));
    }

    private function mutualFollowingCount(int $leftUserId, int $rightUserId, int $appId): int
    {
        $leftTargets = $this->followingParticipantIds($leftUserId, $appId);
        if ($leftTargets->isEmpty()) return 0;
        return DB::table('follows')->where(['app_id' => $appId, 'user_id' => $rightUserId, 'target_type' => 'user'])->whereIn('target_id', $leftTargets)->count();
    }

    private function followingParticipantIds(int $userId, int $appId): Collection
    {
        return DB::table('follows')->where(['app_id' => $appId, 'user_id' => $userId, 'target_type' => 'user'])->pluck('target_id')->map(fn ($id) => (int) $id)->values();
    }

    private function isFollowing(int $userId, int $participantId, int $appId): bool
    {
        return DB::table('follows')->where(['app_id' => $appId, 'user_id' => $userId, 'target_type' => 'user', 'target_id' => $participantId])->exists();
    }

    private function socialSettings(int $userId, int $appId): array
    {
        return $this->normalizeSocialSettings(DB::table('user_social_preferences')->where(['app_id' => $appId, 'user_id' => $userId])->first());
    }

    private function normalizeSocialSettings(?object $row): array
    {
        if (! $row) return self::DEFAULT_SOCIAL_SETTINGS;
        return [
            'discoverable' => (bool) $row->discoverable,
            'show_city' => (bool) $row->show_city,
            'show_interests' => (bool) $row->show_interests,
            'show_event_interests' => (bool) $row->show_event_interests,
            'allow_follows' => (bool) $row->allow_follows,
        ];
    }
}
