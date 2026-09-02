<?php

namespace App\Http\Controllers;

use App\Events\LaoraUserEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LaoraModerationController extends Controller
{
    public function dashboard(Request $request)
    {
        $this->authorizeAdmin($request);
        $since = now()->subDays(30);

        return response()->json(['data' => [
            'profiles' => DB::table('laora_profiles')->count(),
            'discoverable_profiles' => DB::table('laora_profiles')->where('is_complete', true)->where('discovery_enabled', true)->count(),
            'matches_active' => DB::table('laora_matches')->where('status', 'active')->count(),
            'matches_30d' => DB::table('laora_matches')->where('matched_at', '>=', $since)->count(),
            'messages_30d' => DB::table('laora_messages')->where('created_at', '>=', $since)->count(),
            'open_reports' => DB::table('laora_reports')->where('status', 'open')->count(),
            'pending_photos' => DB::table('laora_photos')->where('moderation_status', 'pending')->count(),
            'suspended_users' => DB::table('laora_moderation_actions')->where('action', 'suspend')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->distinct('target_user_id')->count('target_user_id'),
            'banned_users' => DB::table('laora_moderation_actions')->where('action', 'ban')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->distinct('target_user_id')->count('target_user_id'),
        ]]);
    }

    public function reports(Request $request)
    {
        $this->authorizeAdmin($request);
        $status = $request->query('status', 'open');
        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 200);

        $reports = DB::table('laora_reports as reports')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'reports.reporter_user_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'reports.reported_user_id')
            ->select([
                'reports.*',
                'reporter.first_name as reporter_first_name', 'reporter.last_name as reporter_last_name', 'reporter.email as reporter_email',
                'reported.first_name as reported_first_name', 'reported.last_name as reported_last_name', 'reported.email as reported_email',
            ])
            ->when($status !== 'all', fn ($query) => $query->where('reports.status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('reported.email', 'like', $like)
                        ->orWhere('reported.first_name', 'like', $like)
                        ->orWhere('reporter.email', 'like', $like)
                        ->orWhere('reports.reason', 'like', $like)
                        ->orWhere('reports.details', 'like', $like);
                });
            })
            ->orderByDesc('reports.created_at')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $reports]);
    }

    public function photos(Request $request)
    {
        $this->authorizeAdmin($request);
        $status = $request->query('status', 'pending');
        $limit = min(max((int) $request->query('limit', 100), 1), 200);

        $query = DB::table('laora_photos as photos')
            ->join('laora_profiles as profiles', 'profiles.id', '=', 'photos.profile_id')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->select([
                'photos.*', 'profiles.user_id', 'profiles.display_name', 'users.email',
            ]);
        if ($status !== 'all') $query->where('photos.moderation_status', $status);

        $rows = $query->orderByDesc('photos.created_at')->limit($limit)->get()->map(function ($photo) {
            $photo->url = Storage::disk('public')->url($photo->path);
            unset($photo->path);
            return $photo;
        });

        return response()->json(['data' => $rows]);
    }

    public function moderatePhoto(Request $request, int $photoId)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $photo = DB::table('laora_photos')->where('id', $photoId)->first();
        abort_unless($photo, 404, 'Foto não encontrada.');
        $profile = DB::table('laora_profiles')->where('id', $photo->profile_id)->first();
        abort_unless($profile, 404, 'Perfil não encontrado.');

        DB::transaction(function () use ($request, $photo, $profile, $data) {
            DB::table('laora_photos')->where('id', $photo->id)->update([
                'moderation_status' => $data['status'],
                'is_primary' => false,
                'updated_at' => now(),
            ]);

            DB::table('laora_moderation_actions')->insert([
                'report_id' => null,
                'target_user_id' => $profile->user_id,
                'moderator_user_id' => $request->user()->id,
                'action' => $data['status'] === 'approved' ? 'photo_approved' : 'photo_rejected',
                'reason' => trim((string) ($data['reason'] ?? ($data['status'] === 'approved' ? 'Foto aprovada.' : 'Foto rejeitada.'))),
                'expires_at' => null,
                'metadata' => json_encode(['photo_id' => $photo->id], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $approved = DB::table('laora_photos')->where('profile_id', $profile->id)->where('moderation_status', 'approved')->orderBy('position')->orderBy('id')->get();
            DB::table('laora_photos')->where('profile_id', $profile->id)->update(['is_primary' => false, 'updated_at' => now()]);
            if ($approved->isNotEmpty()) DB::table('laora_photos')->where('id', $approved->first()->id)->update(['is_primary' => true, 'updated_at' => now()]);
            DB::table('laora_profiles')->where('id', $profile->id)->update(['is_complete' => $approved->isNotEmpty(), 'updated_at' => now()]);
        });

        $this->broadcastTo((int) $profile->user_id, 'photo.moderated', [
            'photo_id' => $photo->id,
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json(['message' => $data['status'] === 'approved' ? 'Foto aprovada.' : 'Foto rejeitada.']);
    }

    public function user(Request $request, int $userId)
    {
        $this->authorizeAdmin($request);
        $user = DB::table('users')->where('id', $userId)->first([
            'id', 'first_name', 'last_name', 'user_name', 'email', 'phone', 'avatar', 'city', 'uf',
            'email_verified_at', 'created_at', 'updated_at',
        ]);
        abort_unless($user, 404, 'Usuário não encontrado.');
        $profile = DB::table('laora_profiles')->where('user_id', $userId)->first();
        if ($profile) {
            unset($profile->latitude, $profile->longitude);
        }

        $photos = $profile ? DB::table('laora_photos')->where('profile_id', $profile->id)->orderBy('position')->get()->map(function ($photo) {
            $photo->url = Storage::disk('public')->url($photo->path);
            unset($photo->path);
            return $photo;
        }) : collect();

        return response()->json(['data' => [
            'user' => $user,
            'profile' => $profile,
            'photos' => $photos,
            'reports_received' => DB::table('laora_reports')->where('reported_user_id', $userId)->orderByDesc('id')->limit(100)->get(),
            'reports_submitted' => DB::table('laora_reports')->where('reporter_user_id', $userId)->orderByDesc('id')->limit(100)->get(),
            'moderation_actions' => DB::table('laora_moderation_actions')->where('target_user_id', $userId)->orderByDesc('id')->limit(100)->get(),
            'matches_count' => DB::table('laora_matches')->where('user_one_id', $userId)->orWhere('user_two_id', $userId)->count(),
            'messages_count' => DB::table('laora_messages')->where('sender_user_id', $userId)->count(),
            'blocks_count' => DB::table('laora_blocks')->where('blocker_user_id', $userId)->count(),
        ]]);
    }

    public function act(Request $request, int $reportId)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'warn', 'suspend', 'ban'])],
            'reason' => ['required', 'string', 'max:160'],
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'metadata' => ['nullable', 'array'],
        ]);

        $report = DB::table('laora_reports')->where('id', $reportId)->first();
        abort_unless($report, 404, 'Denúncia não encontrada.');
        abort_if($report->status !== 'open', 409, 'Esta denúncia já foi tratada.');

        $expiresAt = null;
        if ($data['action'] === 'suspend') {
            $expiresAt = now()->addHours((int) ($data['duration_hours'] ?? 72));
        }

        DB::transaction(function () use ($request, $report, $data, $expiresAt) {
            DB::table('laora_moderation_actions')->insert([
                'report_id' => $report->id,
                'target_user_id' => $report->reported_user_id,
                'moderator_user_id' => $request->user()->id,
                'action' => $data['action'],
                'reason' => trim($data['reason']),
                'expires_at' => $expiresAt,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('laora_reports')->where('id', $report->id)->update([
                'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'resolved',
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->id,
                'updated_at' => now(),
            ]);

            if (in_array($data['action'], ['suspend', 'ban'], true)) {
                DB::table('laora_profiles')->where('user_id', $report->reported_user_id)->update([
                    'discovery_enabled' => false,
                    'updated_at' => now(),
                ]);
                DB::table('laora_matches')->where('status', 'active')
                    ->where(fn ($q) => $q->where('user_one_id', $report->reported_user_id)->orWhere('user_two_id', $report->reported_user_id))
                    ->update(['status' => 'blocked', 'unmatched_at' => now(), 'unmatched_by_user_id' => $request->user()->id, 'updated_at' => now()]);
            }
        });

        $this->broadcastTo((int) $report->reported_user_id, 'moderation.action', [
            'action' => $data['action'],
            'reason' => $data['reason'],
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);

        return response()->json(['message' => 'Ação de moderação registrada com trilha de auditoria.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasProfile('Administrador') || $user->hasPermission('ecosystem_manage')), 403, 'Acesso restrito à administração.');
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
