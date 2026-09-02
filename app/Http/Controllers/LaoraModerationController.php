<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LaoraModerationController extends Controller
{
    public function reports(Request $request)
    {
        $this->authorizeAdmin($request);
        $status = $request->query('status', 'open');

        $reports = DB::table('laora_reports as reports')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'reports.reporter_user_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'reports.reported_user_id')
            ->select([
                'reports.*',
                'reporter.first_name as reporter_first_name', 'reporter.email as reporter_email',
                'reported.first_name as reported_first_name', 'reported.email as reported_email',
            ])
            ->when($status !== 'all', fn ($query) => $query->where('reports.status', $status))
            ->orderByDesc('reports.created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $reports]);
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
            }
        });

        return response()->json(['message' => 'Ação de moderação registrada com trilha de auditoria.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->hasProfile('Administrador'), 403, 'Acesso restrito à administração.');
    }
}
