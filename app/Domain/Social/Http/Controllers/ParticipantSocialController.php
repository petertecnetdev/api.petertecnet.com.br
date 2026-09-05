<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ParticipantSocialController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'interest' => 'nullable|string|max:80',
            'per_page' => 'nullable|integer|min:6|max:48',
        ]);

        $appId = $this->context->id();
        $viewer = $request->user();
        $perPage = (int) ($data['per_page'] ?? 24);

        $query = User::query()
            ->select(['users.id', 'users.user_name', 'users.first_name', 'users.last_name', 'users.avatar', 'users.city', 'users.uf', 'users.about'])
            ->join('application_user as au', 'au.user_id', '=', 'users.id')
            ->where('au.application_id', $appId)
            ->where('au.status', 'active')
            ->where('users.id', '<>', $viewer->id)
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
            $query->where('users.city', $data['city']);
        }

        if (! empty($data['uf'])) {
            $query->where('users.uf', strtoupper($data['uf']));
        }

        if ($interest = trim((string) ($data['interest'] ?? ''))) {
            $candidateIds = DB::table('application_user_preferences')
                ->where('app_id', $appId)
                ->where('interests', 'like', '%'.$interest.'%')
                ->pluck('user_id');
            $query->whereIn('users.id', $candidateIds);
        }

        $participants = $query
            ->orderByRaw('CASE WHEN users.city IS NULL OR users.city = ? THEN 1 ELSE 0 END', [''])
            ->orderBy('users.first_name')
            ->orderBy('users.id')
            ->paginate($perPage);

        $participants->setCollection(
            $this->decorateParticipants($participants->getCollection(), $viewer->id, $appId)
                ->sortByDesc('affinity_score')
                ->values()
        );

        return response()->json([
            'participants' => $participants,
            'viewer_context' => [
                'interests' => $this->interestsFor($viewer->id, $appId),
                'city' => $viewer->city,
                'uf' => $viewer->uf,
            ],
        ]);
    }

    public function show(Request $request, int $participantId)
    {
        $appId = $this->context->id();
        $viewer = $request->user();
        $participant = $this->participantInApp($participantId, $appId);
        $participantInterests = $this->interestsFor($participant->id, $appId);
        $viewerInterests = $this->interestsFor($viewer->id, $appId);
        $sharedInterests = $this->sharedInterests($viewerInterests, $participantInterests);

        $participantEventIds = DB::table('event_engagements')
            ->where([
                'app_id' => $appId,
                'user_id' => $participant->id,
                'is_interested' => true,
            ])
            ->pluck('event_id');

        $viewerEventIds = DB::table('event_engagements')
            ->where([
                'app_id' => $appId,
                'user_id' => $viewer->id,
                'is_interested' => true,
            ])
            ->pluck('event_id');

        $sharedEventIds = $participantEventIds
            ->intersect($viewerEventIds)
            ->map(fn ($id) => (int) $id)
            ->values();

        $interestedEvents = Event::query()
            ->where('app_id', $appId)
            ->whereIn('id', $participantEventIds)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->where('end_date', '>', now())
            ->with('production:id,app_id,name,slug,logo')
            ->orderBy('start_date')
            ->limit(18)
            ->get();

        $followers = DB::table('follows')
            ->where(['app_id' => $appId, 'target_type' => 'user', 'target_id' => $participant->id])
            ->count();
        $following = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $participant->id, 'target_type' => 'user'])
            ->count();
        $isFollowing = $this->isFollowing($viewer->id, $participant->id, $appId);

        return response()->json([
            'participant' => $this->safeProfile($participant),
            'interests' => $participantInterests,
            'shared_interests' => $sharedInterests,
            'interested_events' => $interestedEvents,
            'shared_event_ids' => $sharedEventIds,
            'stats' => [
                'followers' => $followers,
                'following_participants' => $following,
                'interested_events' => $participantEventIds->count(),
                'shared_interested_events' => $sharedEventIds->count(),
            ],
            'is_following' => $isFollowing,
            'affinity_score' => $this->affinityScore(
                count($sharedInterests),
                $sharedEventIds->count(),
                $this->sameCity($viewer, $participant)
            ),
        ]);
    }

    public function activity(Request $request)
    {
        $data = $request->validate([
            'per_page' => 'nullable|integer|min:5|max:30',
        ]);
        $appId = $this->context->id();
        $viewerId = (int) $request->user()->id;
        $perPage = (int) ($data['per_page'] ?? 12);

        $followedParticipantIds = DB::table('follows')
            ->where([
                'app_id' => $appId,
                'user_id' => $viewerId,
                'target_type' => 'user',
            ])
            ->pluck('target_id');

        if ($followedParticipantIds->isEmpty()) {
            return response()->json([
                'activity' => new LengthAwarePaginator([], 0, $perPage),
            ]);
        }

        $activity = DB::table('event_engagements as ee')
            ->join('users as u', 'u.id', '=', 'ee.user_id')
            ->join('application_user as au', function ($join) use ($appId) {
                $join->on('au.user_id', '=', 'u.id')
                    ->where('au.application_id', '=', $appId)
                    ->where('au.status', '=', 'active');
            })
            ->join('events as e', 'e.id', '=', 'ee.event_id')
            ->leftJoin('productions as p', 'p.id', '=', 'e.production_id')
            ->where('ee.app_id', $appId)
            ->where('e.app_id', $appId)
            ->whereIn('ee.user_id', $followedParticipantIds)
            ->where('ee.is_interested', true)
            ->where('e.is_published', true)
            ->where('e.is_cancelled', false)
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->where('e.end_date', '>', now())
            ->orderByDesc('ee.updated_at')
            ->paginate($perPage, [
                'ee.updated_at as activity_at',
                'u.id as participant_id',
                'u.user_name',
                'u.first_name',
                'u.last_name',
                'u.avatar',
                'e.id as event_id',
                'e.title as event_title',
                'e.slug as event_slug',
                'e.image as event_image',
                'e.start_date',
                'e.city',
                'e.uf',
                'p.name as production_name',
            ]);

        return response()->json(['activity' => $activity]);
    }

    public function follow(Request $request, int $participantId)
    {
        $appId = $this->context->id();
        $viewer = $request->user();
        $participant = $this->participantInApp($participantId, $appId);
        abort_if((int) $participant->id === (int) $viewer->id, 422, 'Você não pode seguir o próprio perfil.');

        $key = [
            'app_id' => $appId,
            'user_id' => $viewer->id,
            'target_type' => 'user',
            'target_id' => $participant->id,
        ];
        $alreadyFollowing = DB::table('follows')->where($key)->exists();

        if ($alreadyFollowing) {
            DB::table('follows')->where($key)->update(['updated_at' => now()]);
        } else {
            DB::table('follows')->insert($key + ['created_at' => now(), 'updated_at' => now()]);
        }

        if (! $alreadyFollowing) {
            $actorName = trim(implode(' ', array_filter([$viewer->first_name, $viewer->last_name])))
                ?: ($viewer->user_name ?: 'Alguém');

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

        return response()->json([
            'message' => 'Agora você segue este participante.',
            'following' => true,
        ]);
    }

    public function unfollow(Request $request, int $participantId)
    {
        $appId = $this->context->id();
        $participant = $this->participantInApp($participantId, $appId);

        DB::table('follows')->where([
            'app_id' => $appId,
            'user_id' => $request->user()->id,
            'target_type' => 'user',
            'target_id' => $participant->id,
        ])->delete();

        return response()->json([
            'message' => 'Você deixou de seguir este participante.',
            'following' => false,
        ]);
    }

    private function decorateParticipants(Collection $participants, int $viewerId, int $appId): Collection
    {
        if ($participants->isEmpty()) {
            return $participants;
        }

        $ids = $participants->pluck('id')->map(fn ($id) => (int) $id)->values();
        $preferences = DB::table('application_user_preferences')
            ->where('app_id', $appId)
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');
        $viewerInterests = $this->interestsFor($viewerId, $appId);
        $viewerEventIds = DB::table('event_engagements')
            ->where(['app_id' => $appId, 'user_id' => $viewerId, 'is_interested' => true])
            ->pluck('event_id');
        $following = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $viewerId, 'target_type' => 'user'])
            ->whereIn('target_id', $ids)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->flip();
        $followerCounts = DB::table('follows')
            ->select('target_id', DB::raw('COUNT(*) as total'))
            ->where(['app_id' => $appId, 'target_type' => 'user'])
            ->whereIn('target_id', $ids)
            ->groupBy('target_id')
            ->pluck('total', 'target_id');
        $sharedEventCounts = collect();

        if ($viewerEventIds->isNotEmpty()) {
            $sharedEventCounts = DB::table('event_engagements')
                ->select('user_id', DB::raw('COUNT(*) as total'))
                ->where('app_id', $appId)
                ->where('is_interested', true)
                ->whereIn('user_id', $ids)
                ->whereIn('event_id', $viewerEventIds)
                ->groupBy('user_id')
                ->pluck('total', 'user_id');
        }

        $viewer = User::query()->select(['id', 'city', 'uf'])->find($viewerId);

        return $participants->map(function (User $participant) use (
            $preferences,
            $viewerInterests,
            $following,
            $followerCounts,
            $sharedEventCounts,
            $viewer
        ) {
            $interests = $this->decodeInterests($preferences->get($participant->id)?->interests ?? null);
            $sharedInterests = $this->sharedInterests($viewerInterests, $interests);
            $sharedEvents = (int) ($sharedEventCounts->get($participant->id) ?? 0);

            return [
                ...$this->safeProfile($participant),
                'interests' => $interests,
                'shared_interests' => $sharedInterests,
                'shared_interested_events' => $sharedEvents,
                'followers_count' => (int) ($followerCounts->get($participant->id) ?? 0),
                'is_following' => $following->has((int) $participant->id),
                'affinity_score' => $this->affinityScore(
                    count($sharedInterests),
                    $sharedEvents,
                    $viewer ? $this->sameCity($viewer, $participant) : false
                ),
            ];
        });
    }

    private function participantInApp(int $participantId, int $appId): User
    {
        return User::query()
            ->select(['users.id', 'users.user_name', 'users.first_name', 'users.last_name', 'users.avatar', 'users.city', 'users.uf', 'users.about'])
            ->join('application_user as au', 'au.user_id', '=', 'users.id')
            ->where('au.application_id', $appId)
            ->where('au.status', 'active')
            ->where('users.id', $participantId)
            ->firstOrFail();
    }

    private function safeProfile(User $participant): array
    {
        return [
            'id' => (int) $participant->id,
            'user_name' => $participant->user_name,
            'first_name' => $participant->first_name,
            'last_name' => $participant->last_name,
            'avatar' => $participant->avatar,
            'city' => $participant->city,
            'uf' => $participant->uf,
            'about' => $participant->about,
        ];
    }

    private function interestsFor(int $userId, int $appId): array
    {
        $raw = DB::table('application_user_preferences')
            ->where(['app_id' => $appId, 'user_id' => $userId])
            ->value('interests');

        return $this->decodeInterests($raw);
    }

    private function decodeInterests(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($interest) => is_string($interest) && trim($interest) !== '')
            ->map(fn ($interest) => trim($interest))
            ->unique(fn ($interest) => mb_strtolower($interest))
            ->values()
            ->take(50)
            ->all();
    }

    private function sharedInterests(array $viewerInterests, array $participantInterests): array
    {
        $participantIndex = collect($participantInterests)
            ->mapWithKeys(fn ($interest) => [mb_strtolower($interest) => $interest]);

        return collect($viewerInterests)
            ->filter(fn ($interest) => $participantIndex->has(mb_strtolower($interest)))
            ->map(fn ($interest) => $participantIndex->get(mb_strtolower($interest)))
            ->values()
            ->all();
    }

    private function affinityScore(int $sharedInterests, int $sharedEvents, bool $sameCity): int
    {
        return min(100, ($sharedInterests * 18) + ($sharedEvents * 12) + ($sameCity ? 10 : 0));
    }

    private function sameCity(User $left, User $right): bool
    {
        if (! $left->city || ! $right->city) {
            return false;
        }

        return mb_strtolower(trim((string) $left->city)) === mb_strtolower(trim((string) $right->city))
            && (! $left->uf || ! $right->uf || strtoupper((string) $left->uf) === strtoupper((string) $right->uf));
    }

    private function isFollowing(int $viewerId, int $participantId, int $appId): bool
    {
        return DB::table('follows')->where([
            'app_id' => $appId,
            'user_id' => $viewerId,
            'target_type' => 'user',
            'target_id' => $participantId,
        ])->exists();
    }
}
