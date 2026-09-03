<?php

namespace App\Services\Reporting;

use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Profile;
use App\Models\User;
use App\Services\Payments\PaymentRevenueRecognitionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdministrativeReportService
{
    private const MAX_ROWS = 1500;

    public function __construct(private PaymentRevenueRecognitionService $recognition)
    {
    }

    public function definitions(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Visão geral', 'description' => 'Indicadores executivos do ecossistema e desempenho por aplicação.'],
            ['key' => 'activity', 'label' => 'Atividade', 'description' => 'Interações, usuários, aplicações, resultado e severidade.'],
            ['key' => 'financial', 'label' => 'Financeiro', 'description' => 'Pagamentos da camada financeira genérica, caixa confirmado e valores em aberto.'],
            ['key' => 'applications', 'label' => 'Aplicações', 'description' => 'Aplicações, vínculos, estabelecimentos e itens.'],
            ['key' => 'users', 'label' => 'Usuários', 'description' => 'Cadastros, perfis, acessos, atividade e estabelecimentos.'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos', 'description' => 'Empresas cadastradas, aprovação, publicação, localidade e aplicação.'],
            ['key' => 'items', 'label' => 'Itens', 'description' => 'Produtos, serviços e demais itens cadastrados no ecossistema.'],
            ['key' => 'audit', 'label' => 'Auditoria', 'description' => 'Histórico administrativo e alterações realizadas no ecossistema.'],
        ];
    }

    public function build(string $type, array $filters): array
    {
        return match ($type) {
            'overview' => $this->overview($filters),
            'activity' => $this->activity($filters),
            'financial' => $this->financial($filters),
            'applications' => $this->applications($filters),
            'users' => $this->users($filters),
            'establishments' => $this->establishments($filters),
            'items' => $this->items($filters),
            'audit' => $this->audit($filters),
            default => abort(404, 'Tipo de relatório não encontrado.'),
        };
    }

    public function metadata(): array
    {
        return [
            'reports' => $this->definitions(),
            'applications' => Application::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'profiles' => Profile::query()->orderBy('name')->get(['id', 'name']),
            'activity_types' => Interaction::query()->select('interaction_type')->distinct()->orderBy('interaction_type')->pluck('interaction_type')->filter()->values(),
            'audit_actions' => EcosystemAuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->filter()->values(),
            'item_types' => Item::query()->select('type')->distinct()->orderBy('type')->pluck('type')->filter()->values(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function overview(array $filters): array
    {
        [$from, $to] = $this->period($filters, true);
        $appId = $this->integerFilter($filters, 'app_id');

        $interaction = Interaction::query()->whereBetween('created_at', [$from, $to]);
        $users = User::query()->whereBetween('created_at', [$from, $to]);
        $establishments = Establishment::query()->whereBetween('created_at', [$from, $to]);
        $items = Item::query()->whereBetween('created_at', [$from, $to]);
        $audit = EcosystemAuditLog::query()->whereBetween('created_at', [$from, $to]);

        if ($appId) {
            $interaction->where('app_id', $appId);
            $establishments->forApplication($appId);
            $items->where('app_id', $appId);
            $users->whereHas('applications', fn ($q) => $q->where('applications.id', $appId));
        }

        $activityByApp = Interaction::query()
            ->selectRaw('app_id, COUNT(*) total, COUNT(DISTINCT user_id) unique_users')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('app_id')
            ->groupBy('app_id')
            ->get()->keyBy('app_id');

        $apps = Application::query()->withCount(['users', 'establishments', 'items'])
            ->when($appId, fn ($q) => $q->whereKey($appId))
            ->orderBy('name')->get();

        $rows = $apps->map(function ($app) use ($activityByApp) {
            $usage = $activityByApp->get($app->id);
            return [
                'application' => $app->name,
                'status' => $app->is_active ? 'Ativa' : 'Inativa',
                'users' => $app->users_count,
                'establishments' => $app->establishments_count,
                'items' => $app->items_count,
                'active_users' => (int) ($usage?->unique_users ?? 0),
                'interactions' => (int) ($usage?->total ?? 0),
            ];
        })->all();

        return $this->report('Visão geral do ecossistema', 'Indicadores consolidados da Peter Tecnet no período selecionado.', $filters, [
            'Aplicações atuais' => Application::count(),
            'Usuários atuais' => User::count(),
            'Novos usuários no período' => $users->count(),
            'Novos estabelecimentos' => $establishments->count(),
            'Novos itens' => $items->count(),
            'Interações' => $interaction->count(),
            'Usuários ativos' => (clone $interaction)->whereNotNull('user_id')->distinct()->count('user_id'),
            'Eventos de auditoria' => $audit->count(),
        ], [
            ['key' => 'application', 'label' => 'Aplicação'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'users', 'label' => 'Usuários'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos'],
            ['key' => 'items', 'label' => 'Itens'],
            ['key' => 'active_users', 'label' => 'Ativos no período'],
            ['key' => 'interactions', 'label' => 'Interações'],
        ], $rows, $from, $to);
    }

    private function activity(array $filters): array
    {
        [$from, $to] = $this->period($filters, true);
        $query = Interaction::query()->with(['user:id,first_name,last_name,user_name,email', 'application:id,name,slug'])
            ->whereBetween('created_at', [$from, $to]);

        if ($appId = $this->integerFilter($filters, 'app_id')) $query->where('app_id', $appId);
        if ($userId = $this->integerFilter($filters, 'user_id')) $query->where('user_id', $userId);
        if ($type = $this->textFilter($filters, 'type')) $query->where('interaction_type', $type);
        if ($outcome = $this->textFilter($filters, 'outcome')) $query->where('outcome', $outcome);
        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $total = (clone $query)->count();
        $uniqueUsers = (clone $query)->whereNotNull('user_id')->distinct()->count('user_id');
        $apps = (clone $query)->whereNotNull('app_id')->distinct()->count('app_id');
        $errors = (clone $query)->where(function ($q) {
            $q->where('outcome', 'error')->orWhere('severity', 'critical')->orWhere('interaction_type', 'request_error');
        })->count();

        $rows = $query->latest('id')->limit(self::MAX_ROWS)->get()->map(fn ($row) => [
            'date' => $this->dateTime($row->created_at),
            'application' => $row->application?->name ?: 'Não identificada',
            'user' => $row->user ? $this->userName($row->user) : 'Visitante / sistema',
            'email' => $row->user?->email ?: '—',
            'type' => $this->humanize($row->interaction_type),
            'outcome' => $this->humanize($row->outcome ?: '—'),
            'severity' => $this->humanize($row->severity ?: 'normal'),
            'route' => trim(($row->method ? $row->method . ' ' : '') . ($row->route ?: '—')),
        ])->all();

        return $this->report('Relatório de atividade', 'Interações registradas pela telemetria central do ecossistema.', $filters, [
            'Interações' => $total,
            'Usuários únicos' => $uniqueUsers,
            'Aplicações com atividade' => $apps,
            'Erros / críticos' => $errors,
        ], [
            ['key' => 'date', 'label' => 'Data'],
            ['key' => 'application', 'label' => 'Aplicação'],
            ['key' => 'user', 'label' => 'Usuário'],
            ['key' => 'email', 'label' => 'E-mail'],
            ['key' => 'type', 'label' => 'Ação'],
            ['key' => 'outcome', 'label' => 'Resultado'],
            ['key' => 'severity', 'label' => 'Severidade'],
            ['key' => 'route', 'label' => 'Rota'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function financial(array $filters): array
    {
        [$from, $to] = $this->period($filters, true);

        if (! Schema::hasTable('ecosystem_payments')) {
            return $this->report('Relatório financeiro', 'A camada financeira genérica ainda não possui registros disponíveis para relatório.', $filters, [
                'Transações' => 0,
                'Caixa confirmado' => $this->money(0),
                'Em aberto' => $this->money(0),
            ], [
                ['key' => 'date', 'label' => 'Data'],
                ['key' => 'application', 'label' => 'Aplicação'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'method', 'label' => 'Método'],
                ['key' => 'gross', 'label' => 'Valor'],
            ], [], $from, $to);
        }

        $periodColumns = collect(['created_at', 'paid_at', 'refunded_at', 'failed_at'])
            ->filter(fn ($column) => Schema::hasColumn('ecosystem_payments', $column))
            ->values();

        $query = DB::table('ecosystem_payments as p')
            ->leftJoin('applications as a', 'a.id', '=', 'p.app_id')
            ->select('p.*', 'a.name as application_name', 'a.slug as application_slug');

        if ($periodColumns->isNotEmpty()) {
            $query->where(function ($q) use ($periodColumns, $from, $to) {
                foreach ($periodColumns as $index => $column) {
                    $method = $index === 0 ? 'whereBetween' : 'orWhereBetween';
                    $q->{$method}("p.{$column}", [$from, $to]);
                }
            });
        }

        if ($appId = $this->integerFilter($filters, 'app_id')) $query->where('p.app_id', $appId);
        if ($status = $this->textFilter($filters, 'status')) $query->where('p.status', $status);
        if ($method = $this->textFilter($filters, 'method')) $query->where('p.method', $method);
        if ($provider = $this->textFilter($filters, 'provider')) $query->where('p.provider', $provider);

        $raw = $query->orderByDesc('p.created_at')->limit(5000)->get()->map(function ($row) {
            return $this->recognition->normalize(json_decode(json_encode($row), true));
        })->filter(function ($row) use ($from, $to) {
            $timestamp = $row['financial_at'] ?? $row['created_at'] ?? null;
            return $timestamp && Carbon::parse($timestamp)->betweenIncluded($from, $to);
        })->values();

        $realized = $this->recognition->realized($raw);
        $failed = $this->recognition->failed($raw);
        $reversed = $this->recognition->reversed($raw);
        $totals = $this->recognition->totals($raw);

        $rows = $raw->take(self::MAX_ROWS)->map(fn ($row) => [
            'date' => $this->dateTime($row['financial_at'] ?? $row['created_at'] ?? null),
            'application' => $row['application_name'] ?? $row['app_slug'] ?? $row['application_slug'] ?? '—',
            'reference' => $row['public_id'] ?? $row['source_reference'] ?? ('#' . ($row['id'] ?? '—')),
            'provider' => $row['provider'] ?? '—',
            'method' => $this->humanize($row['method'] ?? '—'),
            'status' => $this->humanize($row['status'] ?? '—'),
            'gross' => $this->money($row['gross_amount'] ?? 0),
            'platform_fee' => $this->money($row['platform_fee'] ?? 0),
            'seller_net' => $this->money($row['seller_net'] ?? 0),
        ])->all();

        return $this->report('Relatório financeiro', 'Conciliação baseada exclusivamente na camada financeira genérica ecosystem_payments.', $filters, [
            'Transações' => $raw->count(),
            'Pagamentos confirmados' => $realized->count(),
            'Caixa confirmado' => $this->money($totals->gross),
            'Receita Peter Tecnet' => $this->money($totals->platform_fees),
            'Em aberto (não é caixa)' => $this->money($totals->open_gross),
            'Falhas / cancelados' => $failed->count(),
            'Estornos / chargebacks' => $reversed->count(),
        ], [
            ['key' => 'date', 'label' => 'Data financeira'],
            ['key' => 'application', 'label' => 'Aplicação'],
            ['key' => 'reference', 'label' => 'Referência'],
            ['key' => 'provider', 'label' => 'Gateway'],
            ['key' => 'method', 'label' => 'Método'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'gross', 'label' => 'Valor bruto'],
            ['key' => 'platform_fee', 'label' => 'Receita Peter'],
            ['key' => 'seller_net', 'label' => 'Líquido'],
        ], $rows, $from, $to, $raw->count() > self::MAX_ROWS);
    }

    private function applications(array $filters): array
    {
        [$from, $to] = $this->period($filters, false);
        $query = Application::query()->withCount(['users', 'establishments', 'items']);
        $this->applyCreatedPeriod($query, $from, $to);
        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")->orWhere('url', 'like', "%{$search}%"));
        }
        if (($status = $this->textFilter($filters, 'status')) === 'active') $query->where('is_active', true);
        if ($status === 'inactive') $query->where('is_active', false);

        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->limit(self::MAX_ROWS)->get()->map(fn ($app) => [
            'created_at' => $this->date($app->created_at),
            'name' => $app->name,
            'slug' => $app->slug ?: '—',
            'status' => $app->is_active ? 'Ativa' : 'Inativa',
            'users' => $app->users_count,
            'establishments' => $app->establishments_count,
            'items' => $app->items_count,
            'url' => $app->url ?: '—',
        ])->all();

        return $this->report('Relatório de aplicações', 'Inventário administrativo das aplicações do ecossistema.', $filters, [
            'Aplicações encontradas' => $total,
            'Ativas' => (clone $query)->where('is_active', true)->count(),
        ], [
            ['key' => 'created_at', 'label' => 'Cadastro'],
            ['key' => 'name', 'label' => 'Aplicação'],
            ['key' => 'slug', 'label' => 'Slug'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'users', 'label' => 'Usuários'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos'],
            ['key' => 'items', 'label' => 'Itens'],
            ['key' => 'url', 'label' => 'URL'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function users(array $filters): array
    {
        [$from, $to] = $this->period($filters, false);
        $query = User::query()->with('profile:id,name')->withCount(['interactions', 'establishments']);
        $this->applyCreatedPeriod($query, $from, $to);

        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('user_name', 'like', "%{$search}%"));
        }
        if ($profileId = $this->integerFilter($filters, 'profile_id')) $query->where('profile_id', $profileId);
        if ($appId = $this->integerFilter($filters, 'app_id')) $query->whereHas('applications', fn ($q) => $q->where('applications.id', $appId));

        $total = (clone $query)->count();
        $ids = (clone $query)->limit(self::MAX_ROWS)->pluck('id');
        $lastActivity = Interaction::query()->selectRaw('user_id, MAX(created_at) last_activity_at')
            ->whereIn('user_id', $ids)->groupBy('user_id')->pluck('last_activity_at', 'user_id');

        $rows = $query->latest('id')->limit(self::MAX_ROWS)->get()->map(fn ($user) => [
            'created_at' => $this->date($user->created_at),
            'name' => $this->userName($user),
            'email' => $user->email,
            'profile' => $user->profile?->name ?: 'Sem perfil',
            'establishments' => $user->establishments_count,
            'interactions' => $user->interactions_count,
            'last_activity' => $this->dateTime($lastActivity[$user->id] ?? null),
        ])->all();

        return $this->report('Relatório de usuários', 'Cadastros e utilização dos usuários do ecossistema.', $filters, [
            'Usuários encontrados' => $total,
            'Com estabelecimento' => (clone $query)->has('establishments')->count(),
            'Sem estabelecimento' => (clone $query)->doesntHave('establishments')->count(),
        ], [
            ['key' => 'created_at', 'label' => 'Cadastro'],
            ['key' => 'name', 'label' => 'Usuário'],
            ['key' => 'email', 'label' => 'E-mail'],
            ['key' => 'profile', 'label' => 'Perfil'],
            ['key' => 'establishments', 'label' => 'Estabelecimentos'],
            ['key' => 'interactions', 'label' => 'Interações'],
            ['key' => 'last_activity', 'label' => 'Última atividade'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function establishments(array $filters): array
    {
        [$from, $to] = $this->period($filters, false);
        $query = Establishment::query()->with(['app:id,name,slug', 'user:id,first_name,last_name,email']);
        $this->applyCreatedPeriod($query, $from, $to);
        if ($appId = $this->integerFilter($filters, 'app_id')) $query->forApplication($appId);
        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('fantasy', 'like', "%{$search}%")
                ->orWhere('cnpj', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if ($city = $this->textFilter($filters, 'city')) $query->where('city', 'like', "%{$city}%");
        if ($uf = $this->textFilter($filters, 'uf')) $query->where('uf', strtoupper($uf));
        if (($status = $this->textFilter($filters, 'status')) === 'approved') $query->where('is_approved', true);
        if ($status === 'pending') $query->where('is_approved', false);
        if ($status === 'published') $query->where('is_published', true);
        if ($status === 'hidden') $query->where('is_published', false);

        $total = (clone $query)->count();
        $rows = $query->latest('id')->limit(self::MAX_ROWS)->get()->map(fn ($row) => [
            'created_at' => $this->date($row->created_at),
            'name' => $row->fantasy ?: $row->name,
            'document' => $row->cnpj ?: '—',
            'application' => $row->app?->name ?: '—',
            'owner' => $row->user?->email ?: '—',
            'location' => collect([$row->city, $row->uf])->filter()->join(' / ') ?: '—',
            'approval' => $row->is_approved ? 'Aprovado' : 'Pendente',
            'publication' => $row->is_published ? 'Publicado' : 'Oculto',
        ])->all();

        return $this->report('Relatório de estabelecimentos', 'Empresas e estabelecimentos administrados pela Peter Tecnet.', $filters, [
            'Estabelecimentos encontrados' => $total,
            'Aprovados' => (clone $query)->where('is_approved', true)->count(),
            'Publicados' => (clone $query)->where('is_published', true)->count(),
        ], [
            ['key' => 'created_at', 'label' => 'Cadastro'],
            ['key' => 'name', 'label' => 'Estabelecimento'],
            ['key' => 'document', 'label' => 'Documento'],
            ['key' => 'application', 'label' => 'Aplicação'],
            ['key' => 'owner', 'label' => 'Responsável'],
            ['key' => 'location', 'label' => 'Localidade'],
            ['key' => 'approval', 'label' => 'Aprovação'],
            ['key' => 'publication', 'label' => 'Publicação'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function items(array $filters): array
    {
        [$from, $to] = $this->period($filters, false);
        $query = Item::query()->with('establishment:id,name,fantasy');
        $this->applyCreatedPeriod($query, $from, $to);
        if ($appId = $this->integerFilter($filters, 'app_id')) $query->where('app_id', $appId);
        if ($establishmentId = $this->integerFilter($filters, 'establishment_id')) $query->where('entity_name', 'establishment')->where('entity_id', $establishmentId);
        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"));
        }
        if ($type = $this->textFilter($filters, 'type')) $query->where('type', $type);
        if (($status = $this->textFilter($filters, 'status')) === 'active') $query->where('status', true);
        if ($status === 'archived') $query->where('status', false);

        $total = (clone $query)->count();
        $rows = $query->latest('id')->limit(self::MAX_ROWS)->get()->map(fn ($item) => [
            'created_at' => $this->date($item->created_at),
            'name' => $item->name,
            'type' => $this->humanize($item->type ?: 'item'),
            'category' => $item->category ?: '—',
            'establishment' => $item->establishment?->fantasy ?: $item->establishment?->name ?: '—',
            'price' => $this->money($item->price),
            'status' => $item->status === false ? 'Arquivado' : 'Ativo',
        ])->all();

        return $this->report('Relatório de itens', 'Produtos, serviços e demais itens cadastrados no ecossistema.', $filters, [
            'Itens encontrados' => $total,
            'Ativos' => (clone $query)->where('status', true)->count(),
            'Valor médio' => $this->money((clone $query)->avg('price')),
        ], [
            ['key' => 'created_at', 'label' => 'Cadastro'],
            ['key' => 'name', 'label' => 'Item'],
            ['key' => 'type', 'label' => 'Tipo'],
            ['key' => 'category', 'label' => 'Categoria'],
            ['key' => 'establishment', 'label' => 'Estabelecimento'],
            ['key' => 'price', 'label' => 'Preço'],
            ['key' => 'status', 'label' => 'Status'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function audit(array $filters): array
    {
        [$from, $to] = $this->period($filters, true);
        $query = EcosystemAuditLog::query()->with('user:id,first_name,last_name,email')->whereBetween('created_at', [$from, $to]);
        if ($userId = $this->integerFilter($filters, 'user_id')) $query->where('user_id', $userId);
        if ($action = $this->textFilter($filters, 'action')) $query->where('action', $action);
        if ($search = $this->textFilter($filters, 'search')) {
            $query->where(fn ($q) => $q->where('action', 'like', "%{$search}%")
                ->orWhere('entity_type', 'like', "%{$search}%")
                ->orWhere('entity_id', $search)
                ->orWhere('ip', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")));
        }

        $total = (clone $query)->count();
        $rows = $query->latest('id')->limit(self::MAX_ROWS)->get()->map(fn ($log) => [
            'date' => $this->dateTime($log->created_at),
            'administrator' => $log->user?->email ?: 'Sistema',
            'action' => $this->humanize($log->action),
            'entity' => $log->entity_type ? class_basename($log->entity_type) . ($log->entity_id ? ' #' . $log->entity_id : '') : '—',
            'ip' => $log->ip ?: '—',
        ])->all();

        return $this->report('Relatório de auditoria', 'Histórico administrativo e rastreabilidade das alterações no ecossistema.', $filters, [
            'Eventos de auditoria' => $total,
            'Administradores envolvidos' => (clone $query)->whereNotNull('user_id')->distinct()->count('user_id'),
            'Tipos de ação' => (clone $query)->distinct()->count('action'),
        ], [
            ['key' => 'date', 'label' => 'Data'],
            ['key' => 'administrator', 'label' => 'Administrador'],
            ['key' => 'action', 'label' => 'Ação'],
            ['key' => 'entity', 'label' => 'Entidade'],
            ['key' => 'ip', 'label' => 'IP'],
        ], $rows, $from, $to, $total > self::MAX_ROWS);
    }

    private function report(string $title, string $description, array $filters, array $summary, array $columns, array $rows, ?Carbon $from, ?Carbon $to, bool $truncated = false): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'summary' => $summary,
            'columns' => $columns,
            'rows' => $rows,
            'period' => [
                'from' => $from?->format('d/m/Y'),
                'to' => $to?->format('d/m/Y'),
                'label' => $from && $to ? $from->format('d/m/Y') . ' a ' . $to->format('d/m/Y') : 'Sem limite de período',
            ],
            'filters' => $this->filterLabels($filters),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'truncated' => $truncated,
            'max_rows' => self::MAX_ROWS,
        ];
    }

    private function period(array $filters, bool $defaultThirtyDays): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;
        if ($defaultThirtyDays && ! $from && ! $to) {
            $to = now()->endOfDay();
            $from = now()->subDays(29)->startOfDay();
        } elseif ($from && ! $to) {
            $to = now()->endOfDay();
        } elseif (! $from && $to) {
            $from = $to->copy()->subDays(29)->startOfDay();
        }
        return [$from, $to];
    }

    private function applyCreatedPeriod(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from && $to) $query->whereBetween('created_at', [$from, $to]);
        elseif ($from) $query->where('created_at', '>=', $from);
        elseif ($to) $query->where('created_at', '<=', $to);
    }

    private function integerFilter(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function textFilter(array $filters, string $key): ?string
    {
        $value = trim((string) ($filters[$key] ?? ''));
        return $value !== '' ? Str::limit($value, 150, '') : null;
    }

    private function filterLabels(array $filters): array
    {
        $labels = [
            'app_id' => 'Aplicação', 'profile_id' => 'Perfil', 'user_id' => 'Usuário', 'establishment_id' => 'Estabelecimento',
            'search' => 'Busca', 'status' => 'Status', 'type' => 'Tipo', 'outcome' => 'Resultado', 'provider' => 'Gateway',
            'method' => 'Método', 'city' => 'Cidade', 'uf' => 'UF', 'action' => 'Ação',
        ];

        return collect($filters)
            ->reject(fn ($value, $key) => in_array($key, ['from', 'to'], true) || $value === null || $value === '')
            ->mapWithKeys(function ($value, $key) use ($labels) {
                if ($key === 'app_id') $value = Application::query()->whereKey($value)->value('name') ?: $value;
                if ($key === 'profile_id') $value = Profile::query()->whereKey($value)->value('name') ?: $value;
                return [$labels[$key] ?? $this->humanize($key) => $this->humanize((string) $value)];
            })->all();
    }

    private function dateTime($value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i:s') : '—';
    }

    private function date($value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '—';
    }

    private function money($value): string
    {
        return 'R$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
    }

    private function userName($user): string
    {
        return trim(collect([$user->first_name ?? null, $user->last_name ?? null])->filter()->join(' ')) ?: ($user->user_name ?? 'Usuário');
    }

    private function humanize(string $value): string
    {
        if ($value === '—') return $value;
        return Str::of($value)->replace(['_', '.'], ' ')->lower()->title()->toString();
    }
}
