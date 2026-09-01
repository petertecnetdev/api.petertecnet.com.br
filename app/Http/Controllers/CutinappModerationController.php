<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappModerationController extends Controller
{
    private const APP = 'cutinapp';

    public function reports(Request $request)
    {
        $moderator = $this->requestUser($request);
        $this->requireModerator($moderator);
        $appId = $this->applicationId();

        $data = $request->validate([
            'status' => 'nullable|in:open,reviewing,resolved,dismissed',
            'reason' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = DB::table('cutinapp_event_reports as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'r.reviewed_by')
            ->where('r.app_id', $appId)
            ->where('e.app_id', $appId)
            ->where('e.app_slug', self::APP)
            ->select([
                'r.id', 'r.event_id', 'r.user_id', 'r.reason', 'r.details', 'r.status',
                'r.moderation_note', 'r.reviewed_by', 'r.reviewed_at', 'r.created_at', 'r.updated_at',
                'e.title as event_title', 'e.slug as event_slug',
                'u.first_name as reporter_first_name', 'u.last_name as reporter_last_name', 'u.email as reporter_email',
                'reviewer.first_name as reviewer_first_name', 'reviewer.last_name as reviewer_last_name',
            ]);

        if (! empty($data['status'])) $query->where('r.status', $data['status']);
        if (! empty($data['reason'])) $query->where('r.reason', $data['reason']);
        if (! empty($data['q'])) {
            $term = '%' . trim($data['q']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('e.title', 'like', $term)
                    ->orWhere('r.details', 'like', $term)
                    ->orWhere('u.first_name', 'like', $term)
                    ->orWhere('u.last_name', 'like', $term)
                    ->orWhere('u.email', 'like', $term);
            });
        }

        return response()->json([
            'reports' => $query->orderByRaw("CASE r.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
                ->orderByDesc('r.created_at')
                ->paginate((int) ($data['per_page'] ?? 25)),
            'counts' => [
                'open' => DB::table('cutinapp_event_reports')->where(['app_id' => $appId, 'status' => 'open'])->count(),
                'reviewing' => DB::table('cutinapp_event_reports')->where(['app_id' => $appId, 'status' => 'reviewing'])->count(),
                'resolved' => DB::table('cutinapp_event_reports')->where(['app_id' => $appId, 'status' => 'resolved'])->count(),
                'dismissed' => DB::table('cutinapp_event_reports')->where(['app_id' => $appId, 'status' => 'dismissed'])->count(),
            ],
        ]);
    }

    public function updateReport(Request $request, int $reportId)
    {
        $moderator = $this->requestUser($request);
        $this->requireModerator($moderator);
        $appId = $this->applicationId();

        $data = $request->validate([
            'status' => 'required|in:open,reviewing,resolved,dismissed',
            'moderation_note' => 'nullable|string|max:5000',
        ], [
            'status.required' => 'Selecione a situação da denúncia.',
            'status.in' => 'Selecione uma situação válida para a denúncia.',
        ]);

        $report = DB::table('cutinapp_event_reports')
            ->where('app_id', $appId)
            ->where('id', $reportId)
            ->first();
        abort_unless($report, 404, 'Denúncia não encontrada na Cutinapp.');

        DB::table('cutinapp_event_reports')
            ->where('id', $reportId)
            ->update([
                'status' => $data['status'],
                'moderation_note' => $data['moderation_note'] ?? null,
                'reviewed_by' => $moderator->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Denúncia atualizada com sucesso.',
            'report' => DB::table('cutinapp_event_reports')->where('id', $reportId)->first(),
        ]);
    }

    private function requireModerator(User $user): void
    {
        abort_unless($user->hasProfile('Administrador'), 403, 'Apenas a moderação da Cutinapp pode acessar denúncias.');
    }

    private function applicationId(): int
    {
        $id = Application::query()->where('slug', self::APP)->where('is_active', true)->value('id');
        abort_unless($id, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $id;
    }

    private function requestUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());
        abort_if($token === '', 401, 'Sessão inválida ou expirada. Faça login novamente.');
        try { $user = JWTAuth::setToken($token)->authenticate(); } catch (\Throwable) { $user = null; }
        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }
}
