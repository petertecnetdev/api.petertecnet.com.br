<?php

namespace App\Http\Controllers;

use App\Events\LaoraUserEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LaoraController extends Controller
{
    private const PHOTO_LIMIT = 6;

    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = DB::table('laora_profiles')->where('user_id', $user->id)->first();

        return response()->json([
            'data' => $profile ? $this->profilePayload($profile, true) : null,
            'meta' => [
                'email_verified' => (bool) $user->email_verified_at,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'birthdate' => ['required', 'date', 'before_or_equal:' . now()->subYears(18)->toDateString()],
            'gender' => ['nullable', Rule::in(['woman', 'man', 'non_binary', 'other'])],
            'orientation' => ['nullable', Rule::in(['straight', 'gay', 'bisexual', 'pansexual', 'other'])],
            'bio' => ['nullable', 'string', 'max:800'],
            'interests' => ['nullable', 'array', 'max:12'],
            'interests.*' => ['string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'age_min' => ['required', 'integer', 'min:18', 'max:99'],
            'age_max' => ['required', 'integer', 'min:18', 'max:99', 'gte:age_min'],
            'max_distance_km' => ['required', 'integer', 'min:1', 'max:500'],
            'preferred_genders' => ['nullable', 'array', 'max:4'],
            'preferred_genders.*' => [Rule::in(['woman', 'man', 'non_binary', 'other'])],
            'discovery_enabled' => ['boolean'],
        ]);

        if ($request->has('latitude') xor $request->has('longitude')) {
            return response()->json(['message' => 'Latitude e longitude devem ser informadas juntas.'], 422);
        }

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
            'age_min' => $data['age_min'],
            'age_max' => $data['age_max'],
            'max_distance_km' => $data['max_distance_km'],
            'preferred_genders' => json_encode(array_values(array_unique($data['preferred_genders'] ?? [])), JSON_UNESCAPED_UNICODE),
            'discovery_enabled' => $data['discovery_enabled'] ?? true,
            'last_active_at' => $now,
            'updated_at' => $now,
        ];

        if ($request->has('latitude') && $request->has('longitude')) {
            $payload['latitude'] = $data['latitude'];
            $payload['longitude'] = $data['longitude'];
        }

        DB::transaction(function () use ($user, $payload, $now) {
            $exists = DB::table('laora_profiles')->where('user_id', $user->id)->exists();
            if ($exists) {
                DB::table('laora_profiles')->where('user_id', $user->id)->update($payload);
            } else {
                DB::table('laora_profiles')->insert(array_merge($payload, [
                    'user_id' => $user->id,
                    'latitude' => $payload['latitude'] ?? null,
                    'longitude' => $payload['longitude'] ?? null,
                    'is_complete' => false,
                    'created_at' => $now,
                ]));
            }

            $profileId = DB::table('laora_profiles')->where('user_id', $user->id)->value('id');
            $this->refreshProfileCompleteness((int) $profileId);
        });

        return $this->profile($request);
    }

    public function uploadPhoto(Request $request)
    {
        $this->assertActive($request->user());
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);

        $profile = DB::table('laora_profiles')->where('user_id', $request->user()->id)->first();
        if (! $profile) {
            return response()->json(['message' => 'Complete seu perfil antes de adicionar fotos.'], 422);
        }

        $count = DB::table('laora_photos')->where('profile_id', $profile->id)->count();
        if ($count >= self::PHOTO_LIMIT) {
            return response()->json(['message' => 'Você pode manter no máximo 6 fotos no perfil.'], 422);
        }

        $file = $request->file('photo');
        $filename = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('laora/profiles/' . $request->user()->id, $filename, 'public');

        $id = DB::table('laora_photos')->insertGetId([
            'profile_id' => $profile->id,
            'path' => $path,
            'position' => $count,
            'is_primary' => false,
            'moderation_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refreshProfileCompleteness((int) $profile->id);

        return response()->json([
            'data' => $this->photoPayload(DB::table('laora_photos')->where('id', $id)->first(), true),
            'message' => 'Foto enviada e aguardando moderação.',
        ], 201);
    }

    public function deletePhoto(Request $request, int $photoId)
    {
        $profile = DB::table('laora_profiles')->where('user_id', $request->user()->id)->first();
        $photo = $profile ? DB::table('laora_photos')->where('id', $photoId)->where('profile_id', $profile->id)->first() : null;
        if (! $photo) {
            return response()->json(['message' => 'Foto não encontrada.'], 404);
        }

        Storage::disk('public')->delete($photo->path);
        DB::transaction(function () use ($profile, $photoId) {
            DB::table('laora_photos')->where('id', $photoId)->delete();
            $this->normalizePhotos((int) $profile->id);
            $this->refreshProfileCompleteness((int) $profile->id);
        });

        return response()->json(['message' => 'Foto removida.']);
    }

    public function reorderPhotos(Request $request)
    {
        $data = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:' . self::PHOTO_LIMIT],
            'photo_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $profile = DB::table('laora_profiles')->where('user_id', $request->user()->id)->first();
        abort_unless($profile, 404, 'Perfil não encontrado.');

        $owned = DB::table('laora_photos')->where('profile_id', $profile->id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $requested = collect($data['photo_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        abort_unless($owned === $requested, 422, 'A lista deve conter todas as fotos do seu perfil.');

        DB::transaction(function () use ($profile, $data) {
            foreach ($data['photo_ids'] as $position => $photoId) {
                DB::table('laora_photos')->where('profile_id', $profile->id)->where('id', $photoId)->update([
                    'position' => $position,
                    'is_primary' => false,
                    'updated_at' => now(),
                ]);
            }
            $this->normalizePhotos((int) $profile->id);
        });

        return $this->profile($request);
    }

    public function discover(Request $request)
    {
        $user = $request->user();
        $this->assertActive($user);
        $this->assertVerified($user);
        $me = DB::table('laora_profiles')->where('user_id', $user->id)->first();
        if (! $me || ! $me->is_complete || ! $me->discovery_enabled) {
            return response()->json(['data' => [], 'meta' => ['profile_required' => true]]);
        }

        DB::table('laora_profiles')->where('id', $me->id)->update(['last_active_at' => now()]);

        $blockedIds = DB::table('laora_blocks')
            ->where('blocker_user_id', $user->id)->orWhere('blocked_user_id', $user->id)
            ->get()->map(fn ($row) => (int) ($row->blocker_user_id == $user->id ? $row->blocked_user_id : $row->blocker_user_id))->all();
        $seenIds = DB::table('laora_swipes')->where('swiper_user_id', $user->id)->pluck('target_user_id')->map(fn ($id) => (int) $id)->all();
        $excluded = array_values(array_unique(array_merge([$user->id], $blockedIds, $seenIds)));
        $preferred = $this->decodeJson($me->preferred_genders);
        $myAge = Carbon::parse($me->birthdate)->age;

        $query = DB::table('laora_profiles')->where('is_complete', true)->where('discovery_enabled', true)->whereNotIn('user_id', $excluded);
        if ($preferred) {
            $query->whereIn('gender', $preferred);
        }

        $candidates = $query->orderByDesc('last_active_at')->limit(250)->get();
        $data = [];
        foreach ($candidates as $candidate) {
            $age = Carbon::parse($candidate->birthdate)->age;
            if ($age < (int) $me->age_min || $age > (int) $me->age_max || $age < 18) continue;
            if ($myAge < (int) $candidate->age_min || $myAge > (int) $candidate->age_max) continue;

            $candidatePreferred = $this->decodeJson($candidate->preferred_genders);
            if ($candidatePreferred && (! $me->gender || ! in_array($me->gender, $candidatePreferred, true))) continue;

            $distance = $this->distanceKm($me, $candidate);
            if ($me->latitude !== null && $me->longitude !== null && $distance === null) continue;
            if ($distance !== null && ($distance > (int) $me->max_distance_km || $distance > (int) $candidate->max_distance_km)) continue;

            $payload = $this->profilePayload($candidate, false);
            $payload['distance_km'] = $distance === null ? null : (int) round($distance);
            $data[] = $payload;
            if (count($data) >= 30) break;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data), 'has_location' => $me->latitude !== null && $me->longitude !== null]]);
    }

    public function swipe(Request $request)
    {
        $user = $request->user();
        $this->assertActive($user);
        $this->assertVerified($user);
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$user->id])],
            'action' => ['required', Rule::in(['like', 'pass'])],
        ]);
        $targetId = (int) $data['target_user_id'];
        $this->assertNotBlocked($user->id, $targetId);

        $targetProfile = DB::table('laora_profiles')->where('user_id', $targetId)->where('is_complete', true)->where('discovery_enabled', true)->first();
        if (! $targetProfile) return response()->json(['message' => 'Perfil indisponível.'], 404);

        $match = null;
        DB::transaction(function () use ($user, $targetId, $data, &$match) {
            DB::table('laora_swipes')->updateOrInsert(
                ['swiper_user_id' => $user->id, 'target_user_id' => $targetId],
                ['action' => $data['action'], 'updated_at' => now(), 'created_at' => now()]
            );

            if ($data['action'] !== 'like') return;
            $reciprocal = DB::table('laora_swipes')->where('swiper_user_id', $targetId)->where('target_user_id', $user->id)->where('action', 'like')->exists();
            if (! $reciprocal) return;

            [$one, $two] = $this->orderedPair($user->id, $targetId);
            DB::table('laora_matches')->updateOrInsert(
                ['user_one_id' => $one, 'user_two_id' => $two],
                ['status' => 'active', 'matched_at' => now(), 'unmatched_at' => null, 'unmatched_by_user_id' => null, 'updated_at' => now(), 'created_at' => now()]
            );
            $match = DB::table('laora_matches')->where('user_one_id', $one)->where('user_two_id', $two)->first();
        });

        if ($match) {
            $this->broadcastTo($user->id, 'match.created', ['match' => $this->matchPayload($match, $user->id)]);
            $this->broadcastTo($targetId, 'match.created', ['match' => $this->matchPayload($match, $targetId)]);
        }

        return response()->json(['data' => ['matched' => (bool) $match, 'match' => $match ? $this->matchPayload($match, $user->id) : null]]);
    }

    public function matches(Request $request)
    {
        $user = $request->user();
        $this->assertActive($user);
        $this->assertVerified($user);
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $query = DB::table('laora_matches')->where('status', 'active')
            ->where(fn ($q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id));
        if ($request->filled('before_id')) $query->where('id', '<', (int) $request->query('before_id'));
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn ($match) => $this->matchPayload($match, $user->id))->values(),
            'meta' => ['has_more' => $hasMore, 'next_before_id' => $rows->last()?->id],
        ]);
    }

    public function unmatch(Request $request, int $matchId)
    {
        $match = $this->ownedMatch($matchId, $request->user()->id);
        $otherId = $match->user_one_id == $request->user()->id ? $match->user_two_id : $match->user_one_id;
        DB::table('laora_matches')->where('id', $match->id)->update([
            'status' => 'unmatched', 'unmatched_at' => now(), 'unmatched_by_user_id' => $request->user()->id, 'updated_at' => now(),
        ]);
        $this->broadcastTo($otherId, 'match.ended', ['match_id' => $match->id]);
        return response()->json(['message' => 'Match desfeito.']);
    }

    public function messages(Request $request, int $matchId)
    {
        $user = $request->user();
        $this->assertActive($user);
        $this->assertVerified($user);
        $match = $this->ownedMatch($matchId, $user->id, true);
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $query = DB::table('laora_messages')->where('match_id', $match->id)->whereNull('deleted_at');
        if ($request->filled('before_id')) $query->where('id', '<', (int) $request->query('before_id'));
        $messages = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit)->reverse()->values();

        DB::table('laora_messages')->where('match_id', $match->id)->where('sender_user_id', '<>', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
        $otherId = $match->user_one_id == $user->id ? $match->user_two_id : $match->user_one_id;
        $this->broadcastTo($otherId, 'messages.read', ['match_id' => $match->id, 'reader_user_id' => $user->id]);

        return response()->json([
            'data' => $messages,
            'meta' => ['has_more' => $hasMore, 'next_before_id' => $messages->first()?->id],
        ]);
    }

    public function sendMessage(Request $request, int $matchId)
    {
        $user = $request->user();
        $this->assertActive($user);
        $this->assertVerified($user);
        $match = $this->ownedMatch($matchId, $user->id, true);
        $otherId = $match->user_one_id == $user->id ? $match->user_two_id : $match->user_one_id;
        $this->assertNotBlocked($user->id, $otherId);
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $body = trim($data['body']);
        abort_if($body === '', 422, 'A mensagem não pode estar vazia.');

        $recentCount = DB::table('laora_messages')->where('sender_user_id', $user->id)->where('created_at', '>=', now()->subMinute())->count();
        abort_if($recentCount >= 30, 429, 'Muitas mensagens em pouco tempo. Aguarde alguns instantes.');

        $id = DB::table('laora_messages')->insertGetId([
            'match_id' => $match->id, 'sender_user_id' => $user->id, 'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $message = DB::table('laora_messages')->where('id', $id)->first();
        $this->broadcastTo($otherId, 'message.created', ['match_id' => $match->id, 'message' => $message]);

        return response()->json(['data' => $message], 201);
    }

    public function block(Request $request, int $targetUserId)
    {
        $userId = $request->user()->id;
        if ($targetUserId === $userId || ! User::query()->whereKey($targetUserId)->exists()) return response()->json(['message' => 'Usuário inválido.'], 422);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:80']]);
        DB::transaction(function () use ($userId, $targetUserId, $data) {
            DB::table('laora_blocks')->updateOrInsert(
                ['blocker_user_id' => $userId, 'blocked_user_id' => $targetUserId],
                ['reason' => $data['reason'] ?? null, 'updated_at' => now(), 'created_at' => now()]
            );
            [$one, $two] = $this->orderedPair($userId, $targetUserId);
            DB::table('laora_matches')->where('user_one_id', $one)->where('user_two_id', $two)->update([
                'status' => 'blocked', 'unmatched_at' => now(), 'unmatched_by_user_id' => $userId, 'updated_at' => now(),
            ]);
        });
        $this->broadcastTo($targetUserId, 'relationship.blocked', []);
        return response()->json(['message' => 'Usuário bloqueado.']);
    }

    public function unblock(Request $request, int $targetUserId)
    {
        DB::table('laora_blocks')->where('blocker_user_id', $request->user()->id)->where('blocked_user_id', $targetUserId)->delete();
        return response()->json(['message' => 'Bloqueio removido.']);
    }

    public function report(Request $request)
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'reported_user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$userId])],
            'match_id' => ['nullable', 'integer', 'exists:laora_matches,id'],
            'reason' => ['required', Rule::in(['fake_profile', 'harassment', 'spam', 'sexual_content', 'underage', 'violence', 'scam', 'other'])],
            'details' => ['nullable', 'string', 'max:2000'],
            'block_user' => ['boolean'],
        ]);
        if (! empty($data['match_id'])) $this->ownedMatch((int) $data['match_id'], $userId);

        $duplicate = DB::table('laora_reports')->where('reporter_user_id', $userId)->where('reported_user_id', $data['reported_user_id'])->where('status', 'open')->where('created_at', '>=', now()->subDay())->exists();
        abort_if($duplicate, 429, 'Você já enviou uma denúncia recente sobre esta conta.');

        $id = DB::table('laora_reports')->insertGetId([
            'reporter_user_id' => $userId,
            'reported_user_id' => $data['reported_user_id'],
            'match_id' => $data['match_id'] ?? null,
            'reason' => $data['reason'],
            'details' => isset($data['details']) ? trim($data['details']) : null,
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($data['block_user'] ?? true) {
            DB::table('laora_blocks')->updateOrInsert(
                ['blocker_user_id' => $userId, 'blocked_user_id' => $data['reported_user_id']],
                ['reason' => 'report', 'updated_at' => now(), 'created_at' => now()]
            );
            [$one, $two] = $this->orderedPair($userId, (int) $data['reported_user_id']);
            DB::table('laora_matches')->where('user_one_id', $one)->where('user_two_id', $two)->update([
                'status' => 'blocked', 'unmatched_at' => now(), 'unmatched_by_user_id' => $userId, 'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => ['id' => $id, 'status' => 'open'], 'message' => 'Denúncia recebida.'], 201);
    }

    private function profilePayload(object $profile, bool $owner): array
    {
        $photoQuery = DB::table('laora_photos')->where('profile_id', $profile->id)->orderBy('position')->orderBy('id');
        if (! $owner) $photoQuery->where('moderation_status', 'approved');
        $photos = $photoQuery->get()->map(fn ($photo) => $this->photoPayload($photo, $owner))->values()->all();
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

    private function photoPayload(object $photo, bool $owner = false): array
    {
        $payload = ['id' => $photo->id, 'url' => Storage::disk('public')->url($photo->path), 'position' => $photo->position, 'is_primary' => (bool) $photo->is_primary];
        if ($owner) $payload['moderation_status'] = $photo->moderation_status;
        return $payload;
    }

    private function matchPayload(object $match, int $viewerId): array
    {
        $otherId = $match->user_one_id == $viewerId ? $match->user_two_id : $match->user_one_id;
        $profile = DB::table('laora_profiles')->where('user_id', $otherId)->first();
        $lastMessage = DB::table('laora_messages')->where('match_id', $match->id)->whereNull('deleted_at')->orderByDesc('id')->first();
        $unread = DB::table('laora_messages')->where('match_id', $match->id)->where('sender_user_id', '<>', $viewerId)->whereNull('read_at')->whereNull('deleted_at')->count();
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
        $match = DB::table('laora_matches')->where('id', $matchId)
            ->where(fn ($q) => $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId))->first();
        abort_unless($match, 404, 'Match não encontrado.');
        if ($requireActive) abort_unless($match->status === 'active', 409, 'Este match não está ativo.');
        return $match;
    }

    private function assertNotBlocked(int $one, int $two): void
    {
        $blocked = DB::table('laora_blocks')->where(fn ($q) => $q->where('blocker_user_id', $one)->where('blocked_user_id', $two))
            ->orWhere(fn ($q) => $q->where('blocker_user_id', $two)->where('blocked_user_id', $one))->exists();
        abort_if($blocked, 403, 'Interação indisponível entre estas contas.');
    }

    private function assertVerified(User $user): void
    {
        abort_unless($user->email_verified_at, 403, 'Verifique seu e-mail para usar descoberta, matches e mensagens.');
    }

    private function assertActive(User $user): void
    {
        $action = DB::table('laora_moderation_actions')->where('target_user_id', $user->id)
            ->whereIn('action', ['suspend', 'ban'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->first();
        if ($action) {
            abort(403, $action->action === 'ban' ? 'Sua conta está impedida de usar o Laora.' : 'Seu acesso ao Laora está temporariamente suspenso.');
        }
    }

    private function orderedPair(int $one, int $two): array
    {
        return $one < $two ? [$one, $two] : [$two, $one];
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function distanceKm(object $one, object $two): ?float
    {
        if ($one->latitude === null || $one->longitude === null || $two->latitude === null || $two->longitude === null) return null;
        $lat1 = deg2rad((float) $one->latitude); $lat2 = deg2rad((float) $two->latitude);
        $deltaLat = $lat2 - $lat1; $deltaLon = deg2rad((float) $two->longitude - (float) $one->longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function normalizePhotos(int $profileId): void
    {
        $photos = DB::table('laora_photos')->where('profile_id', $profileId)->orderBy('position')->orderBy('id')->get();
        $primaryAssigned = false;
        foreach ($photos as $position => $item) {
            $isPrimary = ! $primaryAssigned && $item->moderation_status === 'approved';
            if ($isPrimary) $primaryAssigned = true;
            DB::table('laora_photos')->where('id', $item->id)->update([
                'position' => $position,
                'is_primary' => $isPrimary,
                'updated_at' => now(),
            ]);
        }
    }

    private function refreshProfileCompleteness(int $profileId): void
    {
        $hasApprovedPhoto = DB::table('laora_photos')->where('profile_id', $profileId)->where('moderation_status', 'approved')->exists();
        DB::table('laora_profiles')->where('id', $profileId)->update(['is_complete' => $hasApprovedPhoto, 'updated_at' => now()]);
        $this->normalizePhotos($profileId);
    }

    private function broadcastTo(int $userId, string $type, array $payload): void
    {
        try {
            event(new LaoraUserEvent($userId, $type, $payload));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
