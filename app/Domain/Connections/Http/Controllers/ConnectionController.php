<?php

namespace App\Domain\Connections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ConnectionController extends Controller
{
    private const PHOTO_LIMIT = 6;

    public function __construct(private readonly ApplicationContext $context) {}

    public function profile(Request $request)
    {
        $profile = $this->table('connection_profiles')->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $profile ? $this->profilePayload($profile, true) : null]);
    }

    public function updateProfile(Request $request)
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'birthdate' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'gender' => ['nullable', 'string', 'max:40'],
            'orientation' => ['nullable', 'string', 'max:40'],
            'bio' => ['nullable', 'string', 'max:800'],
            'interests' => ['nullable', 'array', 'max:12'],
            'interests.*' => ['string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'age_min' => ['required', 'integer', 'min:18', 'max:99'],
            'age_max' => ['required', 'integer', 'min:18', 'max:99', 'gte:age_min'],
            'max_distance_km' => ['required', 'integer', 'min:1', 'max:500'],
            'preferred_genders' => ['nullable', 'array', 'max:10'],
            'preferred_genders.*' => ['string', 'max:40'],
            'discovery_enabled' => ['boolean'],
        ]);

        $now = now();
        $payload = [
            'display_name' => trim($data['display_name']),
            'birthdate' => $data['birthdate'],
            'gender' => $data['gender'] ?? null,
            'orientation' => $data['orientation'] ?? null,
            'bio' => isset($data['bio']) ? trim($data['bio']) : null,
            'interests' => json_encode(array_values(array_unique($data['interests'] ?? [])), JSON_UNESCAPED_UNICODE),
            'city' => $data['city'] ?? null,
            'uf' => isset($data['uf']) ? strtoupper($data['uf']) : null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'age_min' => $data['age_min'],
            'age_max' => $data['age_max'],
            'max_distance_km' => $data['max_distance_km'],
            'preferred_genders' => json_encode(array_values(array_unique($data['preferred_genders'] ?? [])), JSON_UNESCAPED_UNICODE),
            'discovery_enabled' => $data['discovery_enabled'] ?? true,
            'last_active_at' => $now,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($userId, $payload, $now) {
            $key = ['app_id' => $this->appId(), 'user_id' => $userId];
            DB::table('connection_profiles')->updateOrInsert($key, array_merge($payload, [
                'is_complete' => false,
                'created_at' => $now,
            ]));

            $profileId = $this->table('connection_profiles')->where('user_id', $userId)->value('id');
            $hasPhoto = $this->table('connection_profile_photos')
                ->where('profile_id', $profileId)
                ->where('moderation_status', 'approved')
                ->exists();

            $this->table('connection_profiles')->where('id', $profileId)->update(['is_complete' => $hasPhoto]);
        });

        return $this->profile($request);
    }

    public function uploadPhoto(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);

        $profile = $this->table('connection_profiles')->where('user_id', $userId)->first();
        if (! $profile) {
            return response()->json(['message' => 'Complete seu perfil antes de adicionar fotos.'], 422);
        }

        $count = $this->table('connection_profile_photos')->where('profile_id', $profile->id)->count();
        if ($count >= self::PHOTO_LIMIT) {
            return response()->json(['message' => 'Você pode manter no máximo 6 fotos no perfil.'], 422);
        }

        $file = $request->file('photo');
        $filename = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(
            'connections/'.$this->context->slug().'/profiles/'.$userId,
            $filename,
            'public'
        );

        $id = DB::table('connection_profile_photos')->insertGetId([
            'app_id' => $this->appId(),
            'profile_id' => $profile->id,
            'path' => $path,
            'position' => $count,
            'is_primary' => $count === 0,
            'moderation_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->table('connection_profiles')->where('id', $profile->id)->update([
            'is_complete' => true,
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => $this->photoPayload($this->table('connection_profile_photos')->where('id', $id)->first()),
        ], 201);
    }

    public function reorderPhotos(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);
        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:'.self::PHOTO_LIMIT],
            'photo_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $profile = $this->table('connection_profiles')->where('user_id', $userId)->first();
        if (! $profile) {
            return response()->json(['message' => 'Perfil não encontrado.'], 404);
        }

        $requestedIds = array_map('intval', array_values($data['photo_ids']));
        $ownedIds = $this->table('connection_profile_photos')
            ->where('profile_id', $profile->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $requested = $requestedIds;
        $owned = $ownedIds;
        sort($requested);
        sort($owned);
        if ($requested !== $owned) {
            return response()->json(['message' => 'A ordem deve conter exatamente as fotos do seu perfil.'], 422);
        }

        DB::transaction(function () use ($profile, $requestedIds) {
            $now = now();
            foreach ($requestedIds as $position => $photoId) {
                $this->table('connection_profile_photos')
                    ->where('profile_id', $profile->id)
                    ->where('id', $photoId)
                    ->update([
                        'position' => $position,
                        'is_primary' => $position === 0,
                        'updated_at' => $now,
                    ]);
            }
        });

        return response()->json(['data' => $this->profilePayload($profile, true)]);
    }

    public function deletePhoto(Request $request, int $photoId)
    {
        $profile = $this->table('connection_profiles')->where('user_id', $request->user()->id)->first();
        $photo = $profile
            ? $this->table('connection_profile_photos')->where('id', $photoId)->where('profile_id', $profile->id)->first()
            : null;

        if (! $photo) {
            return response()->json(['message' => 'Foto não encontrada.'], 404);
        }

        Storage::disk('public')->delete($photo->path);

        DB::transaction(function () use ($profile, $photoId) {
            $this->table('connection_profile_photos')->where('id', $photoId)->delete();
            $photos = $this->table('connection_profile_photos')
                ->where('profile_id', $profile->id)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($photos as $position => $item) {
                $this->table('connection_profile_photos')->where('id', $item->id)->update([
                    'position' => $position,
                    'is_primary' => $position === 0,
                    'updated_at' => now(),
                ]);
            }

            $this->table('connection_profiles')->where('id', $profile->id)->update([
                'is_complete' => $photos->isNotEmpty(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Foto removida.']);
    }

    public function discover(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);
        $me = $this->table('connection_profiles')->where('user_id', $userId)->first();

        if (! $me || ! $me->is_complete || ! $me->discovery_enabled) {
            return response()->json(['data' => [], 'meta' => ['profile_required' => true]]);
        }

        $this->table('connection_profiles')->where('id', $me->id)->update(['last_active_at' => now()]);

        $blockedIds = $this->table('connection_blocks')
            ->where(function ($query) use ($userId) {
                $query->where('blocker_user_id', $userId)->orWhere('blocked_user_id', $userId);
            })
            ->get()
            ->map(fn ($row) => (int) ($row->blocker_user_id == $userId ? $row->blocked_user_id : $row->blocker_user_id))
            ->all();

        $seenIds = $this->table('connection_decisions')
            ->where('actor_user_id', $userId)
            ->pluck('target_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $excluded = array_values(array_unique(array_merge([$userId], $blockedIds, $seenIds)));
        $preferred = $this->decodeJson($me->preferred_genders);

        $query = $this->table('connection_profiles')
            ->where('is_complete', true)
            ->where('discovery_enabled', true)
            ->whereNotIn('user_id', $excluded);

        if ($preferred) {
            $query->whereIn('gender', $preferred);
        }

        $candidates = $query->orderByDesc('last_active_at')->limit(120)->get();
        $data = [];

        foreach ($candidates as $candidate) {
            $age = Carbon::parse($candidate->birthdate)->age;
            if ($age < (int) $me->age_min || $age > (int) $me->age_max || $age < 18) {
                continue;
            }

            $distance = $this->distanceKm($me, $candidate);
            if ($distance !== null && $distance > (int) $me->max_distance_km) {
                continue;
            }

            $payload = $this->profilePayload($candidate, false);
            $payload['distance_km'] = $distance === null ? null : (int) round($distance);
            $data[] = $payload;

            if (count($data) >= 30) {
                break;
            }
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    public function swipe(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$userId])],
            'action' => ['required', Rule::in(['like', 'pass'])],
        ]);

        $targetId = (int) $data['target_user_id'];
        $this->assertNotBlocked($userId, $targetId);

        $targetProfile = $this->table('connection_profiles')
            ->where('user_id', $targetId)
            ->where('is_complete', true)
            ->first();

        if (! $targetProfile) {
            return response()->json(['message' => 'Perfil indisponível.'], 404);
        }

        $match = null;
        DB::transaction(function () use ($userId, $targetId, $data, &$match) {
            DB::table('connection_decisions')->updateOrInsert(
                ['app_id' => $this->appId(), 'actor_user_id' => $userId, 'target_user_id' => $targetId],
                ['action' => $data['action'], 'updated_at' => now(), 'created_at' => now()]
            );

            if ($data['action'] !== 'like') {
                return;
            }

            $reciprocal = $this->table('connection_decisions')
                ->where('actor_user_id', $targetId)
                ->where('target_user_id', $userId)
                ->where('action', 'like')
                ->exists();

            if (! $reciprocal) {
                return;
            }

            [$one, $two] = $this->orderedPair($userId, $targetId);
            DB::table('connections')->updateOrInsert(
                ['app_id' => $this->appId(), 'user_one_id' => $one, 'user_two_id' => $two],
                [
                    'status' => 'active',
                    'matched_at' => now(),
                    'unmatched_at' => null,
                    'unmatched_by_user_id' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $match = $this->table('connections')
                ->where('user_one_id', $one)
                ->where('user_two_id', $two)
                ->first();
        });

        return response()->json([
            'data' => [
                'matched' => (bool) $match,
                'match' => $match ? $this->matchPayload($match, $userId) : null,
            ],
        ]);
    }

    public function matches(Request $request)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);

        $rows = $this->table('connections')
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->orderByDesc('matched_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($match) => $this->matchPayload($match, $userId))->values(),
        ]);
    }

    public function unmatch(Request $request, int $matchId)
    {
        $match = $this->ownedMatch($matchId, (int) $request->user()->id);
        $this->table('connections')->where('id', $match->id)->update([
            'status' => 'unmatched',
            'unmatched_at' => now(),
            'unmatched_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Conexão desfeita.']);
    }

    public function messages(Request $request, int $matchId)
    {
        $userId = (int) $request->user()->id;
        $match = $this->ownedMatch($matchId, $userId, true);

        $messages = $this->table('connection_messages')
            ->where('connection_id', $match->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        $this->table('connection_messages')
            ->where('connection_id', $match->id)
            ->where('sender_user_id', '<>', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => $messages]);
    }

    public function sendMessage(Request $request, int $matchId)
    {
        $userId = (int) $request->user()->id;
        $this->assertActive($userId);
        $match = $this->ownedMatch($matchId, $userId, true);
        $otherId = $match->user_one_id == $userId ? $match->user_two_id : $match->user_one_id;
        $this->assertNotBlocked($userId, (int) $otherId);

        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $id = DB::table('connection_messages')->insertGetId([
            'app_id' => $this->appId(),
            'connection_id' => $match->id,
            'sender_user_id' => $userId,
            'body' => trim($data['body']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => $this->table('connection_messages')->where('id', $id)->first(),
        ], 201);
    }

    public function block(Request $request, int $targetUserId)
    {
        $userId = (int) $request->user()->id;
        if ($targetUserId === $userId || ! User::query()->whereKey($targetUserId)->exists()) {
            return response()->json(['message' => 'Usuário inválido.'], 422);
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:80']]);

        DB::transaction(function () use ($userId, $targetUserId, $data) {
            DB::table('connection_blocks')->updateOrInsert(
                ['app_id' => $this->appId(), 'blocker_user_id' => $userId, 'blocked_user_id' => $targetUserId],
                ['reason' => $data['reason'] ?? null, 'updated_at' => now(), 'created_at' => now()]
            );

            [$one, $two] = $this->orderedPair($userId, $targetUserId);
            $this->table('connections')
                ->where('user_one_id', $one)
                ->where('user_two_id', $two)
                ->update([
                    'status' => 'blocked',
                    'unmatched_at' => now(),
                    'unmatched_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
        });

        return response()->json(['message' => 'Usuário bloqueado.']);
    }

    public function unblock(Request $request, int $targetUserId)
    {
        $this->table('connection_blocks')
            ->where('blocker_user_id', $request->user()->id)
            ->where('blocked_user_id', $targetUserId)
            ->delete();

        return response()->json(['message' => 'Bloqueio removido.']);
    }

    public function report(Request $request)
    {
        $userId = (int) $request->user()->id;
        $data = $request->validate([
            'reported_user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$userId])],
            'match_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:80'],
            'details' => ['nullable', 'string', 'max:2000'],
            'block_user' => ['boolean'],
        ]);

        if (! empty($data['match_id'])) {
            $this->ownedMatch((int) $data['match_id'], $userId);
        }

        $id = DB::table('connection_reports')->insertGetId([
            'app_id' => $this->appId(),
            'reporter_user_id' => $userId,
            'reported_user_id' => $data['reported_user_id'],
            'connection_id' => $data['match_id'] ?? null,
            'reason' => trim($data['reason']),
            'details' => isset($data['details']) ? trim($data['details']) : null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($data['block_user'] ?? true) {
            DB::table('connection_blocks')->updateOrInsert(
                ['app_id' => $this->appId(), 'blocker_user_id' => $userId, 'blocked_user_id' => $data['reported_user_id']],
                ['reason' => 'report', 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json([
            'data' => ['id' => $id, 'status' => 'open'],
            'message' => 'Denúncia recebida.',
        ], 201);
    }

    private function profilePayload(object $profile, bool $owner): array
    {
        $photos = $this->table('connection_profile_photos')
            ->where('profile_id', $profile->id)
            ->where('moderation_status', 'approved')
            ->orderBy('position')
            ->get()
            ->map(fn ($photo) => $this->photoPayload($photo))
            ->values()
            ->all();

        $payload = [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'display_name' => $profile->display_name,
            'age' => Carbon::parse($profile->birthdate)->age,
            'gender' => $profile->gender,
            'orientation' => $profile->orientation,
            'bio' => $profile->bio,
            'interests' => $this->decodeJson($profile->interests),
            'city' => $profile->city,
            'uf' => $profile->uf,
            'photos' => $photos,
            'last_active_at' => $profile->last_active_at,
        ];

        if ($owner) {
            $payload += [
                'birthdate' => $profile->birthdate,
                'age_min' => $profile->age_min,
                'age_max' => $profile->age_max,
                'max_distance_km' => $profile->max_distance_km,
                'preferred_genders' => $this->decodeJson($profile->preferred_genders),
                'discovery_enabled' => (bool) $profile->discovery_enabled,
                'is_complete' => (bool) $profile->is_complete,
                'has_location' => $profile->latitude !== null && $profile->longitude !== null,
            ];
        }

        return $payload;
    }

    private function photoPayload(object $photo): array
    {
        return [
            'id' => $photo->id,
            'url' => Storage::disk('public')->url($photo->path),
            'position' => $photo->position,
            'is_primary' => (bool) $photo->is_primary,
        ];
    }

    private function matchPayload(object $match, int $viewerId): array
    {
        $otherId = $match->user_one_id == $viewerId ? $match->user_two_id : $match->user_one_id;
        $profile = $this->table('connection_profiles')->where('user_id', $otherId)->first();
        $lastMessage = $this->table('connection_messages')
            ->where('connection_id', $match->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
        $unread = $this->table('connection_messages')
            ->where('connection_id', $match->id)
            ->where('sender_user_id', '<>', $viewerId)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->count();

        return [
            'id' => $match->id,
            'status' => $match->status,
            'matched_at' => $match->matched_at,
            'profile' => $profile ? $this->profilePayload($profile, false) : null,
            'last_message' => $lastMessage,
            'unread_count' => $unread,
        ];
    }

    private function ownedMatch(int $matchId, int $userId, bool $requireActive = false): object
    {
        $match = $this->table('connections')
            ->where('id', $matchId)
            ->where(fn ($query) => $query->where('user_one_id', $userId)->orWhere('user_two_id', $userId))
            ->first();

        abort_unless($match, 404, 'Conexão não encontrada.');
        if ($requireActive) {
            abort_unless($match->status === 'active', 409, 'Esta conexão não está ativa.');
        }

        return $match;
    }

    private function assertNotBlocked(int $one, int $two): void
    {
        $blocked = $this->table('connection_blocks')
            ->where(function ($query) use ($one, $two) {
                $query->where('blocker_user_id', $one)->where('blocked_user_id', $two);
            })
            ->orWhere(function ($query) use ($one, $two) {
                $query->where('app_id', $this->appId())
                    ->where('blocker_user_id', $two)
                    ->where('blocked_user_id', $one);
            })
            ->exists();

        abort_if($blocked, 403, 'Interação indisponível entre estas contas.');
    }

    private function assertActive(int $userId): void
    {
        $action = $this->table('connection_moderation_actions')
            ->where('target_user_id', $userId)
            ->whereIn('action', ['suspend', 'ban'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        abort_if(
            $action,
            403,
            $action?->action === 'ban'
                ? 'Sua conta está impedida de usar este recurso.'
                : 'Seu acesso a este recurso está temporariamente suspenso.'
        );
    }

    private function table(string $table): Builder
    {
        return DB::table($table)->where('app_id', $this->appId());
    }

    private function appId(): int
    {
        return $this->context->id();
    }

    private function orderedPair(int $one, int $two): array
    {
        return $one < $two ? [$one, $two] : [$two, $one];
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function distanceKm(object $one, object $two): ?float
    {
        if ($one->latitude === null || $one->longitude === null || $two->latitude === null || $two->longitude === null) {
            return null;
        }

        $lat1 = deg2rad((float) $one->latitude);
        $lat2 = deg2rad((float) $two->latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $two->longitude - (float) $one->longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}