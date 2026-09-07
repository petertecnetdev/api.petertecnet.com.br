<?php

namespace App\Services\Admin;

use App\Models\AdminUserAnnotation;
use App\Models\AppNotification;
use App\Models\ApplicationAdminAudit;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemPayment;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminUserIntelligenceService
{
    public function augment(User $user, array $base): array
    {
        $resources = $base['resources'] ?? [];
        $platforms = collect($base['platforms'] ?? []);
        $recent = Interaction::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(1000)
            ->get();

        $behavior = $this->behavior($user, $recent);
        $financial = $this->financial($user, $resources);
        $risk = $this->risk($base, $financial, $behavior, $recent);
        $roles = $this->roles($base['user'] ?? [], $resources, $platforms);
        $completeness = $this->profileCompleteness($base['user'] ?? []);
        $health = $this->health(
            $base['user'] ?? [],
            $base['summary'] ?? [],
            $platforms,
            $base['security']['alerts'] ?? [],
            $financial
        );

        $base['summary']['interactions_24h'] = $behavior['interactions_24h'];
        $base['summary']['interactions_1y'] = $behavior['interactions_1y'];

        return array_merge($base, [
            'behavior' => $behavior,
            'financial' => $financial,
            'annotations' => $this->annotations($user),
            'permissions' => $this->permissions($user, $resources, $platforms),
            'audit' => $this->audit($user),
            'communications' => $this->communications($user),
            'sessions' => $this->sessions($recent),
            'profile_completeness' => $completeness,
            'account_health' => $health,
            'risk' => $risk,
            'roles_and_identities' => $roles,
            'operational_state' => $this->operationalState($base['user'] ?? [], $platforms, $risk),
            'insights' => $this->insights($base['user'] ?? [], $base['summary'] ?? [], $behavior, $financial, $roles, $risk),
            'relationships' => $this->relationships($resources, $platforms),
            'diagnostics' => $this->diagnostics($recent),
        ]);
    }

    private function behavior(User $user, Collection $recent): array
    {
        $base = Interaction::query()->where('user_id', $user->id);
        $interactions24h = (clone $base)->where('created_at', '>=', now()->subDay())->count();
        $current30 = (clone $base)->where('created_at', '>=', now()->subDays(30))->count();
        $previous30 = (clone $base)->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])->count();
        $interactions1y = (clone $base)->where('created_at', '>=', now()->subYear())->count();
        $trend = $previous30 > 0
            ? round((($current30 - $previous30) / $previous30) * 100, 1)
            : ($current30 > 0 ? 100.0 : 0.0);

        $activeDays30 = (clone $base)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) AS activity_day')
            ->distinct()
            ->pluck('activity_day')
            ->count();

        $sessions30 = (clone $base)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('session_key')
            ->distinct()
            ->count('session_key');

        $topRoutes = (clone $base)
            ->where('created_at', '>=', now()->subDays(90))
            ->whereNotNull('route')
            ->selectRaw('route, COUNT(*) total')
            ->groupBy('route')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => $row->route, 'total' => (int) $row->total])
            ->values()
            ->all();

        $activitySeries = (clone $base)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) activity_day, COUNT(*) total')
            ->groupBy('activity_day')
            ->orderBy('activity_day')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->activity_day => (int) $row->total]);

        $series = collect(range(29, 0))->map(function ($daysAgo) use ($activitySeries) {
            $day = now()->subDays($daysAgo)->format('Y-m-d');
            return ['day' => $day, 'total' => (int) ($activitySeries[$day] ?? 0)];
        })->all();

        return [
            'active_days_30d' => $activeDays30,
            'sessions_30d' => $sessions30,
            'interactions_24h' => $interactions24h,
            'interactions_30d' => $current30,
            'interactions_1y' => $interactions1y,
            'previous_30d' => $previous30,
            'trend_percent' => $trend,
            'top_routes' => $topRoutes,
            'activity_series_30d' => $series,
            'last_seen_at' => $recent->first()?->created_at,
        ];
    }

    private function financial(User $user, array $resources): array
    {
        if (! Schema::hasTable('ecosystem_payments')) return $this->emptyFinancial();

        $establishmentIds = collect(data_get($resources, 'establishments.data', []))
            ->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

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
        $gross = (float) ($totals->gross_amount ?? 0);

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
        ])->values()->all();

        return [
            'payment_count' => (int) ($totals->payment_count ?? 0),
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
        return [
            'payment_count' => 0, 'paid_count' => 0, 'failed_count' => 0,
            'refunded_count' => 0, 'pending_count' => 0, 'gross_amount' => 0.0,
            'platform_fee' => 0.0, 'provider_fee' => 0.0, 'seller_net' => 0.0,
            'average_ticket' => 0.0, 'take_rate' => 0.0, 'recent' => [],
        ];
    }

    private function annotations(User $user): array
    {
        if (! Schema::hasTable('admin_user_annotations')) return ['notes' => [], 'tags' => []];

        $rows = AdminUserAnnotation::query()
            ->where('target_user_id', $user->id)
            ->with('actor:id,first_name,last_name,email')
            ->latest('created_at')
            ->get();

        return [
            'notes' => $rows->where('kind', 'note')->map(fn ($row) => [
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
            ])->values()->all(),
            'tags' => $rows->where('kind', 'tag')->pluck('value')->unique()->values()->all(),
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
                $rows->push([
                    'permission' => $permission,
                    'source' => 'employment',
                    'source_label' => data_get($employment, 'establishment.name', 'Estabelecimento'),
                    'scope' => 'establishment',
                    'app_id' => $employment['app_id'] ?? null,
                ]);
            }
        }
        foreach ($platforms as $platform) {
            if ($role = data_get($platform, 'access.role')) {
                $rows->push([
                    'permission' => 'role:' . $role,
                    'source' => 'application_role',
                    'source_label' => data_get($platform, 'application.name', 'Aplicação'),
                    'scope' => 'application',
                    'app_id' => data_get($platform, 'application.id'),
                ]);
            }
        }
        $rows = $rows->unique(fn ($row) => implode('|', [$row['permission'], $row['source_label'], $row['scope'], $row['app_id'] ?? '']))->values();
        return ['effective' => $rows->all(), 'count' => $rows->count()];
    }

    private function roles(array $user, array $resources, Collection $platforms): array
    {
        $labels = [
            'producer' => 'Produtor', 'participant' => 'Participante', 'promoter' => 'Promoter',
            'barber' => 'Barbeiro', 'barbershop_owner' => 'Dono de barbearia',
            'partner' => 'Parceiro', 'ticket_seller' => 'Vendedor de ingressos',
        ];
        $rows = collect();
        foreach ((array) ($user['roles'] ?? []) as $key => $active) {
            if ($active) $rows->push(['role' => $labels[$key] ?? $key, 'scope' => 'global', 'context' => 'Ecossistema']);
        }
        foreach ($platforms as $platform) {
            if ($role = data_get($platform, 'access.role')) {
                $rows->push([
                    'role' => $role,
                    'scope' => 'application',
                    'context' => data_get($platform, 'application.name', 'Aplicação'),
                    'app_id' => data_get($platform, 'application.id'),
                ]);
            }
        }
        foreach (data_get($resources, 'employments.data', []) as $employment) {
            $rows->push([
                'role' => $employment['role'] ?? 'Employer',
                'scope' => 'establishment',
                'context' => data_get($employment, 'establishment.name', 'Estabelecimento'),
                'app_id' => $employment['app_id'] ?? null,
            ]);
        }
        return $rows->unique(fn ($row) => implode('|', [$row['role'], $row['scope'], $row['context'], $row['app_id'] ?? '']))->values()->all();
    }

    private function audit(User $user): array
    {
        $rows = collect();
        if (Schema::hasTable('ecosystem_audit_logs')) {
            $rows = EcosystemAuditLog::query()
                ->where('entity_id', $user->id)
                ->whereIn('entity_type', [User::class, 'User', 'user'])
                ->with('user:id,first_name,last_name,email')
                ->latest('created_at')->limit(100)->get()
                ->map(fn ($log) => [
                    'id' => 'eco-' . $log->id,
                    'action' => $log->action,
                    'actor' => $log->user ? (trim($log->user->first_name . ' ' . $log->user->last_name) ?: $log->user->email) : 'Sistema',
                    'before' => $log->before,
                    'after' => $log->after,
                    'ip' => $log->ip,
                    'created_at' => $log->created_at,
                ]);
        }
        if (Schema::hasTable('application_admin_audits')) {
            $applicationRows = ApplicationAdminAudit::query()
                ->where('target_user_id', $user->id)
                ->with('actor:id,first_name,last_name,email')
                ->latest('created_at')->limit(100)->get()
                ->map(fn ($log) => [
                    'id' => 'app-' . $log->id,
                    'action' => $log->action,
                    'actor' => $log->actor ? (trim($log->actor->first_name . ' ' . $log->actor->last_name) ?: $log->actor->email) : 'Sistema',
                    'before' => null,
                    'after' => $log->metadata,
                    'ip' => $log->ip_address,
                    'created_at' => $log->created_at,
                ]);
            $rows = $rows->concat($applicationRows);
        }
        return $rows->sortByDesc('created_at')->take(100)->values()->all();
    }

    private function communications(User $user): array
    {
        $notifications = Schema::hasTable('app_notifications')
            ? AppNotification::query()->where('user_id', $user->id)->latest('created_at')->limit(80)->get()->map(fn ($row) => [
                'id' => 'notification-' . $row->id,
                'channel' => 'notification',
                'subject' => $row->title,
                'message' => Str::limit((string) $row->message, 240),
                'status' => $row->read_at ? 'read' : 'delivered',
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
                'app_id' => $row->app_id,
            ])
            : collect();

        $emails = Schema::hasTable('ecosystem_audit_logs')
            ? EcosystemAuditLog::query()->where('entity_id', $user->id)->where('action', 'user.communication_email.sent')->latest('created_at')->limit(80)->get()->map(fn ($row) => [
                'id' => 'email-' . $row->id,
                'channel' => 'email',
                'subject' => data_get($row->after, 'subject', 'E-mail administrativo'),
                'message' => null,
                'status' => 'sent',
                'read_at' => null,
                'created_at' => $row->created_at,
                'app_id' => null,
            ])
            : collect();

        return $notifications->concat($emails)->sortByDesc('created_at')->take(100)->values()->all();
    }

    private function sessions(Collection $recent): array
    {
        return $recent->filter(fn ($row) => filled($row->session_key))->groupBy('session_key')->map(function ($group, $session) {
            $latest = $group->sortByDesc('created_at')->first();
            $content = is_array($latest?->content) ? $latest->content : [];
            $sessionString = (string) $session;
            $visible = min(5, strlen($sessionString));
            $maskLength = max(strlen($sessionString) - ($visible * 2), 1);
            return [
                'session_key' => Str::mask($sessionString, '*', $visible, $maskLength),
                'last_activity_at' => $latest?->created_at,
                'ip' => $content['ip'] ?? null,
                'user_agent' => Str::limit((string) ($content['user_agent'] ?? ''), 180),
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
        return [
            'score' => (int) round(($done / count($checks)) * 100),
            'complete' => collect($checks)->filter()->keys()->values()->all(),
            'missing' => collect($checks)->reject()->keys()->values()->all(),
        ];
    }

    private function health(array $user, array $summary, Collection $platforms, array $securityAlerts, array $financial): array
    {
        $score = 100;
        $factors = collect();
        if (empty($user['email_verified_at'])) { $score -= 15; $factors->push(['impact' => -15, 'label' => 'E-mail ainda não verificado']); }
        if (empty($user['phone'])) { $score -= 6; $factors->push(['impact' => -6, 'label' => 'Telefone ausente']); }
        if ((int) ($summary['applications'] ?? 0) === 0) { $score -= 10; $factors->push(['impact' => -10, 'label' => 'Sem aplicação vinculada']); }

        $lastActivity = $summary['last_activity_at'] ?? null;
        $inactive = true;
        if ($lastActivity) {
            try { $inactive = Carbon::parse($lastActivity)->lt(now()->subDays(90)); } catch (\Throwable) { $inactive = true; }
        }
        if ($inactive) { $score -= 12; $factors->push(['impact' => -12, 'label' => 'Sem atividade recente']); }

        $blocked = $platforms->filter(fn ($p) => data_get($p, 'access.status') === 'blocked')->count();
        if ($blocked) { $impact = min(20, $blocked * 8); $score -= $impact; $factors->push(['impact' => -$impact, 'label' => $blocked . ' acesso(s) bloqueado(s)']); }

        $warnings = collect($securityAlerts)->filter(fn ($alert) => in_array(strtolower((string) data_get($alert, 'level')), ['warning', 'danger', 'critical', 'error'], true))->count();
        if ($warnings) { $impact = min(20, $warnings * 7); $score -= $impact; $factors->push(['impact' => -$impact, 'label' => $warnings . ' alerta(s) de segurança']); }
        if (($financial['failed_count'] ?? 0) >= 3) { $score -= 8; $factors->push(['impact' => -8, 'label' => 'Falhas de pagamento recorrentes']); }

        $score = max(0, min(100, $score));
        return [
            'score' => $score,
            'level' => $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 50 ? 'attention' : 'critical')),
            'factors' => $factors->values()->all(),
        ];
    }

    private function risk(array $base, array $financial, array $behavior, Collection $recent): array
    {
        $alerts = collect($base['security']['alerts'] ?? [])->map(fn ($alert) => [
            'level' => data_get($alert, 'level', 'info'),
            'type' => 'security',
            'message' => data_get($alert, 'message') ?: data_get($alert, 'label') ?: 'Sinal de segurança detectado.',
        ]);
        if (($financial['failed_count'] ?? 0) >= 3) $alerts->push(['level' => 'warning', 'type' => 'financial', 'message' => 'Há múltiplos pagamentos com falha associados ao usuário ou aos seus estabelecimentos.']);
        if (($financial['refunded_count'] ?? 0) >= 2) $alerts->push(['level' => 'warning', 'type' => 'financial', 'message' => 'O volume de reembolsos merece revisão administrativa.']);
        if (($behavior['trend_percent'] ?? 0) > 500 && ($behavior['interactions_30d'] ?? 0) > 200) $alerts->push(['level' => 'info', 'type' => 'behavior', 'message' => 'A atividade cresceu mais de 5× em relação aos 30 dias anteriores.']);

        $recentFailures = $recent->filter(function ($row) {
            $outcome = strtolower((string) ($row->outcome ?? ''));
            $severity = strtolower((string) ($row->severity ?? ''));
            return in_array($outcome, ['error', 'failed', 'failure'], true) || in_array($severity, ['error', 'critical', 'danger'], true);
        })->count();
        if ($recentFailures >= 5) $alerts->push(['level' => 'warning', 'type' => 'telemetry', 'message' => "{$recentFailures} falhas/erros aparecem nas últimas 1.000 interações registradas."]);

        $score = min(100, $alerts->sum(fn ($alert) => match (strtolower((string) $alert['level'])) {
            'critical', 'danger', 'error' => 35,
            'warning' => 18,
            default => 5,
        }));
        return ['score' => $score, 'level' => $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'), 'alerts' => $alerts->values()->all()];
    }

    private function operationalState(array $user, Collection $platforms, array $risk): array
    {
        $statuses = $platforms->pluck('access.status')->filter();
        if ($statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'blocked')) return ['code' => 'blocked', 'label' => 'Acesso bloqueado', 'tone' => 'danger'];
        if (($risk['level'] ?? 'low') === 'high') return ['code' => 'review', 'label' => 'Revisão recomendada', 'tone' => 'warning'];
        if (empty($user['email_verified_at'])) return ['code' => 'verification_pending', 'label' => 'E-mail pendente', 'tone' => 'warning'];
        return ['code' => 'active', 'label' => 'Operação normal', 'tone' => 'success'];
    }

    private function insights(array $user, array $summary, array $behavior, array $financial, array $roles, array $risk): array
    {
        $name = $user['first_name'] ?? $user['user_name'] ?? 'Este usuário';
        $insights = collect();
        if (($summary['establishments'] ?? 0) || ($summary['events'] ?? 0)) $insights->push("{$name} está ligado a " . (int) ($summary['establishments'] ?? 0) . ' estabelecimento(s) e ' . (int) ($summary['events'] ?? 0) . ' evento(s).');
        if (($summary['items'] ?? 0) > 0) $insights->push('Possui ou administra ' . (int) $summary['items'] . ' item(ns) no ecossistema.');
        if (($behavior['active_days_30d'] ?? 0) > 0) $insights->push('Esteve ativo em ' . $behavior['active_days_30d'] . ' dia(s) nos últimos 30 dias, com variação de ' . $behavior['trend_percent'] . '% contra o período anterior.');
        if (($financial['gross_amount'] ?? 0) > 0) $insights->push('Movimentação bruta associada: R$ ' . number_format((float) $financial['gross_amount'], 2, ',', '.') . ', com take rate observado de ' . number_format((float) $financial['take_rate'], 2, ',', '.') . '%.');
        if (count($roles)) $insights->push('Identidades detectadas: ' . collect($roles)->pluck('role')->unique()->take(5)->join(', ') . '.');
        if (empty($risk['alerts'])) $insights->push('Nenhum sinal relevante de risco foi detectado no recorte atual.');
        return $insights->take(6)->values()->all();
    }

    private function relationships(array $resources, Collection $platforms): array
    {
        $nodes = collect([['id' => 'user', 'type' => 'user', 'label' => 'Usuário', 'count' => 1]]);
        foreach ($platforms as $platform) $nodes->push(['id' => 'app-' . data_get($platform, 'application.id'), 'type' => 'application', 'label' => data_get($platform, 'application.name', 'Aplicação'), 'count' => 1]);
        foreach ($resources as $key => $group) if ((int) ($group['total'] ?? 0) > 0) $nodes->push(['id' => 'resource-' . $key, 'type' => $key, 'label' => $group['label'] ?? $key, 'count' => (int) $group['total']]);
        return $nodes->values()->all();
    }

    private function diagnostics(Collection $recent): array
    {
        $failures = $recent->filter(function ($row) {
            $outcome = strtolower((string) ($row->outcome ?? ''));
            $severity = strtolower((string) ($row->severity ?? ''));
            return in_array($outcome, ['error', 'failed', 'failure'], true) || in_array($severity, ['warning', 'error', 'critical', 'danger'], true);
        });

        $routes = $failures->filter(fn ($row) => filled($row->route))->groupBy('route')->map(fn ($rows, $route) => ['route' => $route, 'count' => $rows->count()])->sortByDesc('count')->take(8)->values()->all();
        $recentFailures = $failures->take(20)->map(fn ($row) => [
            'id' => $row->id,
            'name' => $row->name ?? $row->interaction_type ?? 'Falha registrada',
            'route' => $row->route,
            'outcome' => $row->outcome,
            'severity' => $row->severity,
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'created_at' => $row->created_at,
        ])->values()->all();

        return ['recent_failure_count' => $failures->count(), 'top_failure_routes' => $routes, 'recent_failures' => $recentFailures];
    }
}
