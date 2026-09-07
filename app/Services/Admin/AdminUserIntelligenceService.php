<?php

namespace App\Services\Admin;

use App\Models\AdminUserAnnotation;
use App\Models\AppNotification;
use App\Models\ApplicationAdminAudit;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemPayment;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminUserIntelligenceService
{
    public function augment(User $user, array $base): array
    {
        $summary = $base['summary'] ?? [];
        $resources = $base['resources'] ?? [];
        $platforms = collect($base['platforms'] ?? []);
        $recent = Interaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(1000)
            ->get();

        $behavior = $this->behavior($user, $recent);
        $financial = $this->financial($user, $resources);
        $annotations = $this->annotations($user);
        $permissions = $this->permissions($user, $resources, $platforms);
        $audit = $this->audit($user);
        $communications = $this->communications($user);
        $sessions = $this->sessions($recent);
        $completeness = $this->profileCompleteness($base['user'] ?? []);
        $health = $this->health($base['user'] ?? [], $summary, $platforms, $base['security']['alerts'] ?? [], $financial);
        $risk = $this->risk($base, $financial, $behavior);
        $roles = $this->roles($base['user'] ?? [], $resources, $platforms);
        $operational = $this->operationalState($base['user'] ?? [], $platforms, $risk);
        $insights = $this->insights($base['user'] ?? [], $summary, $behavior, $financial, $roles, $risk);

        return $base + [
            'behavior' => $behavior,
            'financial' => $financial,
            'annotations' => $annotations,
            'permissions' => $permissions,
            'audit' => $audit,
            'communications' => $communications,
            'sessions' => $sessions,
            'profile_completeness' => $completeness,
            'account_health' => $health,
            'risk' => $risk,
            'roles_and_identities' => $roles,
            'operational_state' => $operational,
            'insights' => $insights,
            'relationships' => $this->relationships($resources, $platforms),
        ];
    }

    public function storeNote(User $target, ?User $actor, array $data, Request $request): array
    {
        $note = AdminUserAnnotation::query()->create([
            'target_user_id' => $target->id,
            'actor_user_id' => $actor?->id,
            'kind' => 'note',
            'value' => trim($data['message']),
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
        ]);
        $this->writeAudit($request, $target, 'user.admin_note.created', null, ['annotation_id' => $note->id, 'is_pinned' => $note->is_pinned]);
        return $this->annotationPayload($note->load('actor:id,first_name,last_name,email'));
    }

    public function deleteNote(User $target, AdminUserAnnotation $note, ?User $actor, Request $request): void
    {
        $before = ['annotation_id' => $note->id, 'is_pinned' => $note->is_pinned, 'value_sha256' => hash('sha256', $note->value)];
        $note->delete();
        $this->writeAudit($request, $target, 'user.admin_note.deleted', $before, null);
    }

    public function replaceTags(User $target, ?User $actor, array $tags, Request $request): array
    {
        $normalized = collect($tags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn ($tag) => mb_strtolower($tag))
            ->take(30)
            ->values();

        $before = AdminUserAnnotation::query()
            ->where('target_user_id', $target->id)
            ->where('kind', 'tag')
            ->pluck('value')
            ->values()
            ->all();

        DB::transaction(function () use ($target, $actor, $normalized) {
            AdminUserAnnotation::query()->where('target_user_id', $target->id)->where('kind', 'tag')->delete();
            foreach ($normalized as $tag) {
                AdminUserAnnotation::query()->create([
                    'target_user_id' => $target->id,
                    'actor_user_id' => $actor?->id,
                    'kind' => 'tag',
                    'value' => $tag,
                ]);
            }
        });

        $after = $normalized->all();
        $this->writeAudit($request, $target, 'user.tags.updated', ['tags' => $before], ['tags' => $after]);
        return $after;
    }

    public function revokeSessions(User $target, ?User $actor, Request $request): array
    {
        $before = max((int) $target->auth_version, 1);
        $target->forceFill(['auth_version' => $before + 1])->save();
        $this->writeAudit($request, $target, 'user.sessions.revoked', ['auth_version' => $before], ['auth_version' => $before + 1]);

        return [
            'message' => 'Sessões e tokens JWT anteriores foram invalidados.',
            'revoked_at' => now()->toIso8601String(),
        ];
    }

    public function setAccountAccess(User $target, ?User $actor, string $status, Request $request): array
    {
        $before = DB::table('application_user')->where('user_id', $target->id)->pluck('status', 'application_id')->all();
        DB::table('application_user')->where('user_id', $target->id)->update(['status' => $status, 'updated_at' => now()]);
        if ($status === 'blocked') {
            $target->forceFill(['auth_version' => max((int) $target->auth_version, 1) + 1])->save();
        }
        $after = DB::table('application_user')->where('user_id', $target->id)->pluck('status', 'application_id')->all();
        $this->writeAudit($request, $target, 'user.account_access.updated', ['applications' => $before], ['applications' => $after, 'status' => $status]);

        return [
            'message' => $status === 'blocked' ? 'Acesso bloqueado em todas as aplicações vinculadas.' : 'Acesso liberado nas aplicações vinculadas.',
            'status' => $status,
            'applications_affected' => count($after),
        ];
    }

    private function behavior(User $user, Collection $recent): array
    {
        $base = Interaction::query()->where('user_id', $user->id);
        $current30 = (clone $base)->where('created_at', '>=', now()->subDays(30))->count();
        $previous30 = (clone $base)->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])->count();
        $trend = $previous30 > 0 ? round((($current30 - $previous30) / $previous30) * 100, 1) : ($current30 > 0 ? 100.0 : 0.0);
        $activeDays30 = (clone $base)->where('created_at', '>=', now()->subDays(30))->selectRaw('DATE(created_at) day')->distinct()->count('day');
        $sessions30 = (clone $base)->where('created_at', '>=', now()->subDays(30))->whereNotNull('session_key')->distinct()->count('session_key');

        $topRoutes = (clone $base)->where('created_at', '>=', now()->subDays(90))->whereNotNull('route')
            ->selectRaw('route, COUNT(*) total')->groupBy('route')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row) => ['label' => $row->route, 'total' => (int) $row->total])->values();

        $activitySeries = (clone $base)->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) day, COUNT(*) total')->groupBy('day')->orderBy('day')->pluck('total', 'day');
        $series = collect(range(29, 0))->map(function ($daysAgo) use ($activitySeries) {
            $day = now()->subDays($daysAgo)->format('Y-m-d');
            return ['day' => $day, 'total' => (int) ($activitySeries[$day] ?? 0)];
        });

        return [
            'active_days_30d' => $activeDays30,
            'sessions_30d' => $sessions30,
            'interactions_30d' => $current30,
            'previous_30d' => $previous30,
            'trend_percent' => $trend,
            'top_routes' => $topRoutes,
            'activity_series_30d' => $series,
            'last_seen_at' => $recent->first()?->created_at,
        ];
    }

    private function financial(User $user, array $resources): array
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return $this->emptyFinancial();
        }

        $establishmentIds = collect(data_get($resources, 'establishments.data', []))->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $query = EcosystemPayment::query()->where(function ($q) use ($user, $establishmentIds) {
            $q->where('user_id', $user->id);
            if ($establishmentIds->isNotEmpty()) $q->orWhereIn('establishment_id', $establishmentIds);
        });

        $totals = (clone $query)->selectRaw(
            'COUNT(*) payment_count, COALESCE(SUM(gross_amount),0) gross_amount, COALESCE(SUM(platform_fee),0) platform_fee, COALESCE(SUM(provider_fee),0) provider_fee, COALESCE(SUM(seller_net),0) seller_net'
        )->first();
        $paid = (clone $query)->whereIn('status', ['paid', 'approved', 'confirmed'])->count();
        $failed = (clone $query)->whereIn('status', ['failed', 'rejected', 'cancelled'])->count();
        $refunded = (clone $query)->whereNotNull('refunded_at')->count();
        $pending = (clone $query)->whereIn('status', ['pending', 'processing', 'in_process'])->count();
        $recent = (clone $query)->latest('id')->limit(50)->get()->map(fn ($payment) => [
            'id' => $payment->id,
            'public_id' => $payment->public_id,
            'app_id' => $payment->app_id,
            'status' => $payment->status,
            'method' => $payment->method,
            'gross_amount' => (float) $payment->gross_amount,
            'platform_fee' => (float) $payment->platform_fee,
            'seller_net' => (float) $payment->seller_net,
            'paid_at' => $payment->paid_at,
            'refunded_at' => $payment->refunded_at,
            'created_at' => $payment->created_at,
        ])->values();

        $gross = (float) ($totals->gross_amount ?? 0);
        $count = (int) ($totals->payment_count ?? 0);
        return [
            'payment_count' => $count,
            'paid_count' => $paid,
            'failed_count' => $failed,
            'refunded_count' => $refunded,
            'pending_count' => $pending,
            'gross_amount' => $gross,
            'platform_fee' => (float) ($totals->platform_fee ?? 0),
            'provider_fee' => (float) ($totals->provider_fee ?? 0),
            'seller_net' => (float) ($totals->seller_net ?? 0),
            'average_ticket' => $paid > 0 ? round($gross / $paid, 2) : 0.0,
            'take_rate' => $gross > 0 ? round(((float) ($totals->platform_fee ?? 0) / $gross) * 100, 2) : 0.0,
            'recent' => $recent,
        ];
    }

    private function emptyFinancial(): array
    {
        return ['payment_count' => 0, 'paid_count' => 0, 'failed_count' => 0, 'refunded_count' => 0, 'pending_count' => 0, 'gross_amount' => 0, 'platform_fee' => 0, 'provider_fee' => 0, 'seller_net' => 0, 'average_ticket' => 0, 'take_rate' => 0, 'recent' => []];
    }

    private function annotations(User $user): array
    {
        if (! Schema::hasTable('admin_user_annotations')) return ['notes' => [], 'tags' => []];
        $rows = AdminUserAnnotation::query()->where('target_user_id', $user->id)->with('actor:id,first_name,last_name,email')->latest('created_at')->get();
        return [
            'notes' => $rows->where('kind', 'note')->map(fn ($row) => $this->annotationPayload($row))->values(),
            'tags' => $rows->where('kind', 'tag')->pluck('value')->unique()->values(),
        ];
    }

    private function annotationPayload(AdminUserAnnotation $row): array
    {
        return [
            'id' => $row->id,
            'kind' => $row->kind,
            'value' => $row->value,
            'is_pinned' => (bool) $row->is_pinned,
            'actor' => $row->actor ? [
                'id' => $row->actor->id,
                'name' => trim($row->actor->first_name . ' ' . $row->actor->last_name) ?: $row->actor->email,
                'email' => $row->actor->email,
            ] : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function permissions(User $user, array $resources, Collection $platforms): array
    {
        $rows = collect();
        foreach ((array) ($user->profile?->permissions ?? []) as $permission) {
            $rows->push(['permission' => $permission, 'source' => 'profile', 'source_label' => $user->profile?->name ?: 'Perfil global', 'scope' => 'ecosystem']);
        }
        foreach (data_get($resources, 'employments.data', []) as $employment) {
            foreach ((array) ($employment['permissions'] ?? []) as $permission) {
                $rows->push(['permission' => $permission, 'source' => 'employment', 'source_label' => data_get($employment, 'establishment.name', 'Estabelecimento'), 'scope' => 'establishment', 'app_id' => $employment['app_id'] ?? null]);
            }
        }
        foreach ($platforms as $platform) {
            if ($role = data_get($platform, 'access.role')) {
                $rows->push(['permission' => 'role:' . $role, 'source' => 'application_role', 'source_label' => data_get($platform, 'application.name', 'Aplicação'), 'scope' => 'application', 'app_id' => data_get($platform, 'application.id')]);
            }
        }
        return ['effective' => $rows->unique(fn ($row) => implode('|', [$row['permission'], $row['source_label'], $row['scope']]))->values(), 'count' => $rows->count()];
    }

    private function roles(array $user, array $resources, Collection $platforms): array
    {
        $labels = ['producer' => 'Produtor', 'participant' => 'Participante', 'promoter' => 'Promoter', 'barber' => 'Barbeiro', 'barbershop_owner' => 'Dono de barbearia', 'partner' => 'Parceiro', 'ticket_seller' => 'Vendedor de ingressos'];
        $rows = collect();
        foreach ((array) ($user['roles'] ?? []) as $key => $active) if ($active) $rows->push(['role' => $labels[$key] ?? $key, 'scope' => 'global', 'context' => 'Ecossistema']);
        foreach ($platforms as $platform) if ($role = data_get($platform, 'access.role')) $rows->push(['role' => $role, 'scope' => 'application', 'context' => data_get($platform, 'application.name', 'Aplicação'), 'app_id' => data_get($platform, 'application.id')]);
        foreach (data_get($resources, 'employments.data', []) as $employment) $rows->push(['role' => $employment['role'] ?? 'Employer', 'scope' => 'establishment', 'context' => data_get($employment, 'establishment.name', 'Estabelecimento'), 'app_id' => $employment['app_id'] ?? null]);
        return $rows->unique(fn ($row) => implode('|', [$row['role'], $row['scope'], $row['context']]))->values()->all();
    }

    private function audit(User $user): array
    {
        $rows = collect();
        if (Schema::hasTable('ecosystem_audit_logs')) {
            $rows = EcosystemAuditLog::query()->where('entity_id', $user->id)->where(function ($q) {
                $q->where('entity_type', User::class)->orWhere('entity_type', 'User')->orWhere('entity_type', 'user');
            })->with('user:id,first_name,last_name,email')->latest('created_at')->limit(100)->get()->map(fn ($log) => [
                'id' => 'eco-' . $log->id,
                'action' => $log->action,
                'actor' => $log->user ? trim($log->user->first_name . ' ' . $log->user->last_name) ?: $log->user->email : 'Sistema',
                'before' => $log->before,
                'after' => $log->after,
                'ip' => $log->ip,
                'created_at' => $log->created_at,
            ]);
        }
        if (Schema::hasTable('application_admin_audits')) {
            $appRows = ApplicationAdminAudit::query()->where('target_user_id', $user->id)->with('actor:id,first_name,last_name,email')->latest('created_at')->limit(100)->get()->map(fn ($log) => [
                'id' => 'app-' . $log->id,
                'action' => $log->action,
                'actor' => $log->actor ? trim($log->actor->first_name . ' ' . $log->actor->last_name) ?: $log->actor->email : 'Sistema',
                'before' => null,
                'after' => $log->metadata,
                'ip' => $log->ip_address,
                'created_at' => $log->created_at,
            ]);
            $rows = $rows->concat($appRows);
        }
        return $rows->sortByDesc('created_at')->take(100)->values()->all();
    }

    private function communications(User $user): array
    {
        $notifications = Schema::hasTable('app_notifications') ? AppNotification::query()->where('user_id', $user->id)->latest('created_at')->limit(80)->get()->map(fn ($row) => [
            'id' => 'notification-' . $row->id,
            'channel' => 'notification',
            'subject' => $row->title,
            'message' => Str::limit((string) $row->message, 240),
            'status' => $row->read_at ? 'read' : 'delivered',
            'read_at' => $row->read_at,
            'created_at' => $row->created_at,
            'app_id' => $row->app_id,
        ]) : collect();

        $emails = Schema::hasTable('ecosystem_audit_logs') ? EcosystemAuditLog::query()->where('entity_id', $user->id)->where('action', 'user.communication_email.sent')->latest('created_at')->limit(80)->get()->map(fn ($row) => [
            'id' => 'email-' . $row->id,
            'channel' => 'email',
            'subject' => data_get($row->after, 'subject', 'E-mail administrativo'),
            'message' => null,
            'status' => 'sent',
            'read_at' => null,
            'created_at' => $row->created_at,
            'app_id' => null,
        ]) : collect();

        return $notifications->concat($emails)->sortByDesc('created_at')->take(100)->values()->all();
    }

    private function sessions(Collection $recent): array
    {
        return $recent->filter(fn ($row) => filled($row->session_key))->groupBy('session_key')->map(function ($group, $session) {
            $latest = $group->sortByDesc('created_at')->first();
            return [
                'session_key' => Str::mask((string) $session, '*', 5, max(strlen((string) $session) - 10, 1)),
                'last_activity_at' => $latest?->created_at,
                'ip' => data_get($latest?->content, 'ip'),
                'user_agent' => Str::limit((string) data_get($latest?->content, 'user_agent'), 180),
                'interactions' => $group->count(),
            ];
        })->sortByDesc('last_activity_at')->take(20)->values()->all();
    }

    private function profileCompleteness(array $user): array
    {
        $checks = [
            'E-mail' => filled($user['email'] ?? null),
            'Usuário' => filled($user['user_name'] ?? null),
            'Telefone' => filled($user['phone'] ?? null),
            'Foto' => filled($user['avatar'] ?? null),
            'Cidade/UF' => filled($user['city'] ?? null) && filled($user['uf'] ?? null),
            'Nascimento' => filled($user['birthdate'] ?? null),
            'Ocupação' => filled($user['occupation'] ?? null),
            'Sobre' => filled($user['about'] ?? null),
        ];
        $done = collect($checks)->filter()->count();
        return ['score' => (int) round(($done / count($checks)) * 100), 'complete' => collect($checks)->filter()->keys()->values(), 'missing' => collect($checks)->reject()->keys()->values()];
    }

    private function health(array $user, array $summary, Collection $platforms, array $securityAlerts, array $financial): array
    {
        $score = 100;
        $factors = collect();
        if (empty($user['email_verified_at'])) { $score -= 15; $factors->push(['impact' => -15, 'label' => 'E-mail ainda não verificado']); }
        if (empty($user['phone'])) { $score -= 6; $factors->push(['impact' => -6, 'label' => 'Telefone ausente']); }
        if ((int) ($summary['applications'] ?? 0) === 0) { $score -= 10; $factors->push(['impact' => -10, 'label' => 'Sem aplicação vinculada']); }
        if (empty($summary['last_activity_at']) || now()->diffInDays($summary['last_activity_at']) > 90) { $score -= 12; $factors->push(['impact' => -12, 'label' => 'Sem atividade recente']); }
        $blocked = $platforms->filter(fn ($p) => data_get($p, 'access.status') === 'blocked')->count();
        if ($blocked) { $score -= min(20, $blocked * 8); $factors->push(['impact' => -min(20, $blocked * 8), 'label' => $blocked . ' acesso(s) bloqueado(s)']); }
        $warnings = collect($securityAlerts)->filter(fn ($a) => in_array(data_get($a, 'level'), ['warning', 'danger', 'critical'], true))->count();
        if ($warnings) { $score -= min(20, $warnings * 7); $factors->push(['impact' => -min(20, $warnings * 7), 'label' => $warnings . ' alerta(s) de segurança']); }
        if (($financial['failed_count'] ?? 0) >= 3) { $score -= 8; $factors->push(['impact' => -8, 'label' => 'Falhas de pagamento recorrentes']); }
        $score = max(0, min(100, $score));
        return ['score' => $score, 'level' => $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 50 ? 'attention' : 'critical')), 'factors' => $factors->values()];
    }

    private function risk(array $base, array $financial, array $behavior): array
    {
        $alerts = collect($base['security']['alerts'] ?? [])->map(fn ($a) => ['level' => data_get($a, 'level', 'info'), 'type' => 'security', 'message' => data_get($a, 'message')]);
        if (($financial['failed_count'] ?? 0) >= 3) $alerts->push(['level' => 'warning', 'type' => 'financial', 'message' => 'Há múltiplos pagamentos com falha associados ao usuário ou aos seus estabelecimentos.']);
        if (($financial['refunded_count'] ?? 0) >= 2) $alerts->push(['level' => 'warning', 'type' => 'financial', 'message' => 'O volume de reembolsos merece revisão administrativa.']);
        if (($behavior['trend_percent'] ?? 0) > 500 && ($behavior['interactions_30d'] ?? 0) > 200) $alerts->push(['level' => 'info', 'type' => 'behavior', 'message' => 'A atividade cresceu mais de 5× em relação aos 30 dias anteriores.']);
        $score = min(100, $alerts->sum(fn ($a) => match ($a['level']) { 'critical', 'danger' => 35, 'warning' => 18, default => 5 }));
        return ['score' => $score, 'level' => $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'), 'alerts' => $alerts->values()];
    }

    private function operationalState(array $user, Collection $platforms, array $risk): array
    {
        $statuses = $platforms->pluck('access.status')->filter();
        $blocked = $statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'blocked');
        if ($blocked) return ['code' => 'blocked', 'label' => 'Acesso bloqueado', 'tone' => 'danger'];
        if (($risk['level'] ?? 'low') === 'high') return ['code' => 'review', 'label' => 'Revisão recomendada', 'tone' => 'warning'];
        if (empty($user['email_verified_at'])) return ['code' => 'verification_pending', 'label' => 'E-mail pendente', 'tone' => 'warning'];
        return ['code' => 'active', 'label' => 'Operação normal', 'tone' => 'success'];
    }

    private function insights(array $user, array $summary, array $behavior, array $financial, array $roles, array $risk): array
    {
        $name = $user['first_name'] ?? $user['user_name'] ?? 'Este usuário';
        $insights = collect();
        if (($summary['establishments'] ?? 0) || ($summary['events'] ?? 0)) $insights->push("{$name} está ligado a {$summary['establishments']} estabelecimento(s) e {$summary['events']} evento(s).");
        if (($summary['items'] ?? 0) > 0) $insights->push("Possui ou administra {$summary['items']} item(ns) no ecossistema.");
        if (($behavior['active_days_30d'] ?? 0) > 0) $insights->push("Esteve ativo em {$behavior['active_days_30d']} dia(s) nos últimos 30 dias, com variação de {$behavior['trend_percent']}% contra o período anterior.");
        if (($financial['gross_amount'] ?? 0) > 0) $insights->push('Movimentação bruta associada: R$ ' . number_format((float) $financial['gross_amount'], 2, ',', '.') . ', com take rate observado de ' . number_format((float) $financial['take_rate'], 2, ',', '.') . '%.');
        if (count($roles)) $insights->push('Identidades detectadas: ' . collect($roles)->pluck('role')->unique()->take(5)->join(', ') . '.');
        if (($risk['alerts'] ?? collect())->isEmpty()) $insights->push('Nenhum sinal relevante de risco foi detectado no recorte atual.');
        return $insights->take(6)->values()->all();
    }

    private function relationships(array $resources, Collection $platforms): array
    {
        $nodes = collect([['id' => 'user', 'type' => 'user', 'label' => 'Usuário', 'count' => 1]]);
        foreach ($platforms as $platform) $nodes->push(['id' => 'app-' . data_get($platform, 'application.id'), 'type' => 'application', 'label' => data_get($platform, 'application.name', 'Aplicação'), 'count' => 1]);
        foreach ($resources as $key => $group) if ((int) ($group['total'] ?? 0) > 0) $nodes->push(['id' => 'resource-' . $key, 'type' => $key, 'label' => $group['label'] ?? $key, 'count' => (int) $group['total']]);
        return $nodes->values()->all();
    }

    private function writeAudit(Request $request, User $target, string $action, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return;
        EcosystemAuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $target->id,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }
}
