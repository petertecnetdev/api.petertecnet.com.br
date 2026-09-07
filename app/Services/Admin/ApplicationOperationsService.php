<?php

namespace App\Services\Admin;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationOperationsService
{
    private const CONFIRMED_PAYMENT_STATUSES = ['approved', 'paid'];
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function __construct(
        private readonly EstablishmentEventTicketAnalyticsService $ticketAnalytics,
    ) {
    }

    public function dashboard(Application $application, int $days = 30): array
    {
        $days = max(1, min($days, 365));
        $since = now()->subDays($days);
        $appId = (int) $application->id;
        $slug = (string) $application->slug;

        $events = Event::query()
            ->where('app_id', $appId)
            ->orderByDesc('start_date')
            ->limit(250)
            ->get([
                'id', 'app_id', 'production_id', 'title', 'slug', 'category', 'image', 'venue', 'city', 'uf',
                'start_date', 'end_date', 'latitude', 'longitude', 'is_featured', 'is_published', 'is_approved',
                'is_cancelled', 'max_attendees', 'remaining_tickets', 'created_at', 'updated_at',
            ]);
        $events = $this->ticketAnalytics->attachSummaries($events);
        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->values();

        $productions = Establishment::query()
            ->forApplication($appId)
            ->orderByDesc('updated_at')
            ->limit(150)
            ->get([
                'id', 'app_id', 'user_id', 'name', 'fantasy', 'slug', 'type', 'category', 'phone', 'email',
                'city', 'uf', 'address', 'latitude', 'longitude', 'is_published', 'is_approved', 'is_featured',
                'is_cancelled', 'created_at', 'updated_at',
            ]);
        $productionIds = $productions->pluck('id')->map(fn ($id) => (int) $id)->values();

        $users = $this->users($appId);
        $userIds = collect($users)->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        $items = $this->items($appId);
        $itemIds = collect($items)->pluck('id')->map(fn ($id) => (int) $id)->values();
        $orders = $this->orders($appId);
        $payments = $this->payments($appId);
        $passes = $this->passes($appId);
        $promoters = $this->promoters($appId);
        $campaigns = $this->campaigns($appId);
        $moderation = $this->moderation($appId);
        $notifications = $this->notifications($appId);
        $support = $this->support($appId);
        $schedules = $this->schedules($appId);
        $issues = $this->issues($appId);
        $audit = $this->audit($eventIds, $productionIds, $itemIds, $userIds);
        $analytics = $this->analytics($appId, $events, $since, $orders, $passes);
        $finance = $this->finance($appId, $orders, $payments);
        $summary = $this->summary($events, $productions, $users, $items, $orders, $payments, $passes, $finance);
        $rankings = $this->rankings($events, $productions, $orders);
        $alerts = $this->alerts($events, $payments, $passes, $support, $moderation, $issues, $appId);
        $map = $this->map($events, $productions);
        $modules = $this->modules($summary, $events, $promoters, $campaigns, $moderation, $support, $schedules, $issues, $audit);

        return [
            'application' => [
                'id' => $appId,
                'name' => $application->name,
                'slug' => $slug,
                'url' => $application->url,
                'logo' => $application->logo,
                'operational_status' => $application->operational_status,
                'capabilities' => $application->capabilities ?: [],
            ],
            'window_days' => $days,
            'summary' => $summary,
            'finance' => $finance,
            'events' => $events->values(),
            'productions' => $productions->values(),
            'users' => $users,
            'items' => $items,
            'orders' => $orders,
            'payments' => $payments,
            'passes' => $passes,
            'promoters' => $promoters,
            'campaigns' => $campaigns,
            'moderation' => $moderation,
            'notifications' => $notifications,
            'support' => $support,
            'schedules' => $schedules,
            'issues' => $issues,
            'audit' => $audit,
            'analytics' => $analytics,
            'rankings' => $rankings,
            'alerts' => $alerts,
            'map' => $map,
            'modules' => $modules,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function users(int $appId): array
    {
        if (! Schema::hasTable('application_user')) return [];

        return DB::table('application_user as au')
            ->join('users as u', 'u.id', '=', 'au.user_id')
            ->where('au.application_id', $appId)
            ->orderByDesc('au.updated_at')
            ->limit(150)
            ->get([
                'u.id', 'u.first_name', 'u.last_name', 'u.user_name', 'u.email', 'u.phone', 'u.created_at',
                'au.role', 'au.status', 'au.joined_at', 'au.updated_at as access_updated_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function items(int $appId): array
    {
        if (! Schema::hasTable('items')) return [];

        return DB::table('items')
            ->where('app_id', $appId)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(150)
            ->get(['id', 'entity_id', 'entity_name', 'name', 'type', 'category', 'sku', 'price', 'stock', 'status', 'is_featured', 'created_at', 'updated_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function orders(int $appId): array
    {
        if (! Schema::hasTable('commerce_orders')) return [];

        return DB::table('commerce_orders')
            ->where('app_id', $appId)
            ->orderByDesc('created_at')
            ->limit(150)
            ->get([
                'id', 'public_id', 'event_id', 'production_id', 'user_id', 'status', 'currency', 'subtotal',
                'platform_fee', 'processor_fee', 'discount_amount', 'total', 'producer_net', 'payment_method',
                'expires_at', 'paid_at', 'cancelled_at', 'created_at', 'updated_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function payments(int $appId): array
    {
        if (! Schema::hasTable('ecosystem_payments')) return [];

        return DB::table('ecosystem_payments')
            ->where('app_id', $appId)
            ->orderByDesc('created_at')
            ->limit(150)
            ->get([
                'id', 'public_id', 'provider', 'provider_payment_id', 'source_type', 'source_reference', 'user_id',
                'production_id', 'establishment_id', 'method', 'status', 'gross_amount', 'platform_fee', 'provider_fee',
                'seller_net', 'paid_at', 'refunded_at', 'failed_at', 'reconciled_at', 'reconciliation_status', 'created_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function passes(int $appId): array
    {
        if (! Schema::hasTable('event_passes') || ! Schema::hasTable('events')) return [];

        $query = DB::table('event_passes as ep')
            ->join('events as e', 'e.id', '=', 'ep.event_id')
            ->leftJoin('tickets as t', 't.id', '=', 'ep.ticket_id')
            ->leftJoin('users as u', 'u.id', '=', 'ep.user_id')
            ->where('e.app_id', $appId)
            ->orderByDesc('ep.updated_at')
            ->limit(200);

        return $query->get([
            'ep.id', 'ep.event_id', 'ep.ticket_id', 'ep.user_id', 'ep.holder_name', 'ep.holder_email', 'ep.status',
            'ep.checked_in_at', 'ep.checked_in_by', 'ep.commerce_order_item_id', 'ep.created_at', 'ep.updated_at',
            'e.title as event_title', 'e.start_date as event_start_date', 't.name as ticket_name', 't.ticket_type',
            'u.email as user_email',
        ])->map(fn ($row) => (array) $row)->all();
    }

    private function promoters(int $appId): array
    {
        if (! Schema::hasTable('event_acquisition_commissions')) return [];

        $sales = Schema::hasTable('commerce_orders')
            ? DB::table('commerce_orders')->where('app_id', $appId)->where('status', 'paid')
                ->selectRaw('event_id, COALESCE(SUM(total),0) gross_sales, COUNT(*) paid_orders')
                ->groupBy('event_id')->get()->keyBy('event_id')
            : collect();

        return DB::table('event_acquisition_commissions as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_user_id')
            ->leftJoin('events as e', 'e.id', '=', 'c.event_id')
            ->leftJoin('acquisition_referrals as r', 'r.id', '=', 'c.referral_id')
            ->where('c.application_id', $appId)
            ->orderByDesc('c.updated_at')
            ->limit(150)
            ->get([
                'c.id', 'c.agent_user_id', 'c.event_id', 'c.percentage', 'c.basis', 'c.created_at', 'c.updated_at',
                'u.first_name', 'u.last_name', 'u.user_name', 'u.email', 'e.title as event_title', 'r.status as referral_status',
            ])->map(function ($row) use ($sales) {
                $sale = $sales->get($row->event_id);
                $gross = (float) ($sale->gross_sales ?? 0);
                return array_merge((array) $row, [
                    'gross_sales' => round($gross, 2),
                    'paid_orders' => (int) ($sale->paid_orders ?? 0),
                    'commission_amount' => round($gross * ((float) $row->percentage / 100), 2),
                ]);
            })->all();
    }

    private function campaigns(int $appId): array
    {
        if (! Schema::hasTable('notification_campaigns')) return [];

        return DB::table('notification_campaigns')
            ->where('app_id', $appId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get([
                'id', 'audience_type', 'type', 'title', 'message', 'channels', 'status', 'scheduled_at', 'recipients_count',
                'delivered_count', 'failed_count', 'sent_at', 'completed_at', 'created_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function moderation(int $appId): array
    {
        if (! Schema::hasTable('connection_reports')) return [];

        return DB::table('connection_reports as r')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'r.reporter_user_id')
            ->leftJoin('users as reported', 'reported.id', '=', 'r.reported_user_id')
            ->where('r.app_id', $appId)
            ->orderByDesc('r.created_at')
            ->limit(100)
            ->get(['r.*', 'reporter.email as reporter_email', 'reported.email as reported_email'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function notifications(int $appId): array
    {
        if (! Schema::hasTable('app_notifications')) return [];

        return DB::table('app_notifications')
            ->where('app_id', $appId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'user_id', 'campaign_id', 'type', 'title', 'message', 'reference_type', 'reference_id', 'read_at', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function support(int $appId): array
    {
        if (! Schema::hasTable('support_tickets')) return [];

        return DB::table('support_tickets')
            ->where('application_id', $appId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get([
                'id', 'public_id', 'user_id', 'establishment_id', 'assigned_to_user_id', 'requester_name', 'requester_email',
                'subject', 'category', 'priority', 'status', 'channel', 'source_url', 'last_message_at', 'first_response_at',
                'resolved_at', 'closed_at', 'created_at', 'updated_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function schedules(int $appId): array
    {
        if (! Schema::hasTable('event_schedules')) return [];

        return DB::table('event_schedules')
            ->where('app_id', $appId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get([
                'id', 'production_id', 'title', 'description', 'category', 'day_of_week', 'start_time', 'end_time',
                'venue', 'address', 'city', 'uf', 'latitude', 'longitude', 'max_attendees', 'is_active', 'created_at', 'updated_at',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function issues(int $appId): array
    {
        if (! Schema::hasTable('operational_issues')) return [];

        return DB::table('operational_issues')
            ->where(function ($q) use ($appId) {
                $q->where('application_id', $appId)->orWhere('latest_application_id', $appId);
            })
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get([
                'id', 'public_id', 'title', 'description', 'category', 'domain', 'status', 'severity', 'priority', 'impact_score',
                'occurrence_count', 'users_affected_count', 'establishments_affected_count', 'first_seen_at', 'last_seen_at',
                'latest_http_status', 'latest_error_code', 'latest_method', 'latest_route', 'latest_message', 'assigned_to',
            ])->map(fn ($row) => (array) $row)->all();
    }

    private function audit(Collection $eventIds, Collection $productionIds, Collection $itemIds, Collection $userIds): array
    {
        if (! Schema::hasTable('ecosystem_audit_logs')) return [];

        $sets = [
            ['event', $eventIds], ['establishment', $productionIds], ['production', $productionIds],
            ['item', $itemIds], ['ticket', collect()], ['user', $userIds],
        ];

        $query = DB::table('ecosystem_audit_logs')->orderByDesc('created_at')->limit(120);
        $query->where(function ($outer) use ($sets) {
            $hasAny = false;
            foreach ($sets as [$type, $ids]) {
                if ($ids->isEmpty()) continue;
                $hasAny = true;
                $outer->orWhere(function ($inner) use ($type, $ids) {
                    $inner->where('entity_type', 'like', "%{$type}%")->whereIn('entity_id', $ids->take(500)->all());
                });
            }
            if (! $hasAny) $outer->whereRaw('1 = 0');
        });

        return $query->get(['id', 'user_id', 'action', 'entity_type', 'entity_id', 'ip', 'user_agent', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function finance(int $appId, array $orders, array $payments): array
    {
        $paidOrders = collect($orders)->filter(fn ($row) => ($row['status'] ?? null) === 'paid');
        $confirmedPayments = collect($payments)->filter(fn ($row) => in_array($row['status'] ?? null, self::CONFIRMED_PAYMENT_STATUSES, true));
        $gross = (float) $confirmedPayments->sum(fn ($row) => (float) ($row['gross_amount'] ?? 0));
        $platform = (float) $confirmedPayments->sum(fn ($row) => (float) ($row['platform_fee'] ?? 0));
        $provider = (float) $confirmedPayments->sum(fn ($row) => (float) ($row['provider_fee'] ?? 0));
        $sellerNet = (float) $confirmedPayments->sum(fn ($row) => (float) ($row['seller_net'] ?? 0));

        return [
            'gmv' => round((float) $paidOrders->sum(fn ($row) => (float) ($row['total'] ?? 0)), 2),
            'gross_payments' => round($gross, 2),
            'platform_revenue' => round($platform, 2),
            'provider_fees' => round($provider, 2),
            'seller_net' => round($sellerNet, 2),
            'contribution_after_provider_fee' => round($platform - $provider, 2),
            'take_rate' => $gross > 0 ? round(($platform / $gross) * 100, 2) : 0.0,
            'paid_orders' => $paidOrders->count(),
            'avg_paid_order' => $paidOrders->count() ? round((float) $paidOrders->avg(fn ($row) => (float) ($row['total'] ?? 0)), 2) : 0.0,
            'discounts' => round((float) $paidOrders->sum(fn ($row) => (float) ($row['discount_amount'] ?? 0)), 2),
            'failed_payments' => collect($payments)->whereIn('status', ['failed', 'rejected', 'cancelled', 'expired'])->count(),
            'reversed_payments' => collect($payments)->whereIn('status', ['refunded', 'charged_back', 'chargeback'])->count(),
            'application_id' => $appId,
        ];
    }

    private function summary(Collection $events, Collection $productions, array $users, array $items, array $orders, array $payments, array $passes, array $finance): array
    {
        $now = now();
        $validPasses = collect($passes)->reject(fn ($row) => in_array($row['status'] ?? null, self::INVALID_PASS_STATUSES, true));

        return [
            'events_total' => $events->count(),
            'events_upcoming' => $events->filter(fn ($e) => ! $e->is_cancelled && $e->start_date && $e->start_date->gte($now))->count(),
            'events_live' => $events->filter(fn ($e) => ! $e->is_cancelled && $e->start_date && $e->end_date && $e->start_date->lte($now) && $e->end_date->gte($now))->count(),
            'events_published' => $events->where('is_published', true)->where('is_cancelled', false)->count(),
            'events_featured' => $events->where('is_featured', true)->where('is_cancelled', false)->count(),
            'productions_total' => $productions->count(),
            'users_total' => count($users),
            'items_total' => count($items),
            'ticket_types' => (int) $events->sum('ticket_types_count'),
            'ticket_capacity' => (int) $events->sum('ticket_capacity'),
            'tickets_sold' => (int) $events->sum('tickets_sold_count'),
            'tickets_available' => (int) $events->sum('tickets_available_count'),
            'courtesies' => (int) $events->sum('courtesy_count'),
            'checkins' => $validPasses->whereNotNull('checked_in_at')->count(),
            'passes_active' => $validPasses->count(),
            'orders_total' => count($orders),
            'payments_total' => count($payments),
            'gmv' => $finance['gmv'],
            'platform_revenue' => $finance['platform_revenue'],
            'take_rate' => $finance['take_rate'],
        ];
    }

    private function analytics(int $appId, Collection $events, $since, array $orders, array $passes): array
    {
        $byType = [];
        $byDay = [];
        $activeUsers = 0;
        $eventViews = 0;
        $checkoutStarts = 0;
        $shareActions = 0;
        $socialActions = 0;

        if (Schema::hasTable('interactions')) {
            $byType = DB::table('interactions')
                ->where('app_id', $appId)->where('created_at', '>=', $since)
                ->selectRaw('interaction_type, COUNT(*) total')
                ->groupBy('interaction_type')->orderByDesc('total')->limit(30)->get()
                ->map(fn ($row) => (array) $row)->all();

            $byDay = DB::table('interactions')
                ->where('app_id', $appId)->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) day, COUNT(*) total, COUNT(DISTINCT user_id) users')
                ->groupByRaw('DATE(created_at)')->orderBy('day')->get()
                ->map(fn ($row) => (array) $row)->all();

            $activeUsers = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', $since)->whereNotNull('user_id')->distinct()->count('user_id');
            $eventViews = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', $since)
                ->where(function ($q) {
                    $q->where('interaction_type', 'like', '%event%view%')
                        ->orWhere(fn ($i) => $i->where('entity_type', 'like', '%event%')->where('interaction_type', 'view'));
                })->count();
            $checkoutStarts = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', $since)
                ->where(function ($q) {
                    $q->where('interaction_type', 'like', '%checkout%')->orWhere('route', 'like', '%checkout%')->orWhere('name', 'like', '%checkout%');
                })->count();
            $shareActions = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', $since)
                ->where(function ($q) { $q->where('interaction_type', 'like', '%share%')->orWhere('name', 'like', '%share%'); })->count();
            $socialActions = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', $since)
                ->where(function ($q) {
                    $q->where('interaction_type', 'like', '%feed%')->orWhere('interaction_type', 'like', '%community%')
                        ->orWhere('interaction_type', 'like', '%comment%')->orWhere('interaction_type', 'like', '%like%');
                })->count();
        }

        $paidOrders = collect($orders)->where('status', 'paid')->count();
        $checkins = collect($passes)->whereNotNull('checked_in_at')->reject(fn ($row) => in_array($row['status'] ?? null, self::INVALID_PASS_STATUSES, true))->count();
        $conversion = $eventViews > 0 ? round(($paidOrders / $eventViews) * 100, 2) : 0.0;

        return [
            'active_users' => $activeUsers,
            'interactions_by_type' => $byType,
            'interactions_by_day' => $byDay,
            'social_actions' => $socialActions,
            'share_actions' => $shareActions,
            'funnel' => [
                'event_views' => $eventViews,
                'checkout_starts' => $checkoutStarts,
                'paid_orders' => $paidOrders,
                'checkins' => $checkins,
                'view_to_paid_conversion' => $conversion,
            ],
            'ratings' => Schema::hasTable('event_ratings')
                ? [
                    'count' => DB::table('event_ratings')->where('app_id', $appId)->count(),
                    'average' => round((float) DB::table('event_ratings')->where('app_id', $appId)->avg('rating'), 2),
                ]
                : ['count' => 0, 'average' => 0],
        ];
    }

    private function rankings(Collection $events, Collection $productions, array $orders): array
    {
        $eventRanking = $events->sortByDesc(fn ($event) => (float) $event->gross_ticket_revenue)
            ->take(15)->values()->map(fn ($event) => [
                'id' => (int) $event->id,
                'title' => $event->title,
                'production_id' => (int) $event->production_id,
                'sold' => (int) $event->tickets_sold_count,
                'revenue' => round((float) $event->gross_ticket_revenue, 2),
                'checkins' => (int) $event->checked_in_count,
                'available' => (int) $event->tickets_available_count,
            ])->all();

        $orderGroups = collect($orders)->where('status', 'paid')->groupBy('production_id');
        $productionRanking = $productions->map(function ($production) use ($orderGroups) {
            $rows = $orderGroups->get($production->id, collect());
            return [
                'id' => (int) $production->id,
                'name' => $production->fantasy ?: $production->name,
                'paid_orders' => $rows->count(),
                'gmv' => round((float) $rows->sum(fn ($row) => (float) ($row['total'] ?? 0)), 2),
            ];
        })->sortByDesc('gmv')->take(15)->values()->all();

        return ['events' => $eventRanking, 'productions' => $productionRanking];
    }

    private function alerts(Collection $events, array $payments, array $passes, array $support, array $moderation, array $issues, int $appId): array
    {
        $alerts = collect();
        $publishedWithoutTickets = $events->filter(fn ($e) => $e->is_published && ! $e->is_cancelled && (int) $e->ticket_types_count === 0)->count();
        if ($publishedWithoutTickets) $alerts->push(['severity' => 'warning', 'title' => 'Eventos publicados sem ingressos', 'message' => "{$publishedWithoutTickets} evento(s) estão públicos sem lote cadastrado.", 'module' => 'events']);

        $failedPayments = collect($payments)->whereIn('status', ['failed', 'rejected', 'cancelled', 'expired'])->count();
        if ($failedPayments) $alerts->push(['severity' => 'critical', 'title' => 'Falhas de pagamento', 'message' => "{$failedPayments} pagamento(s) recentes falharam ou expiraram.", 'module' => 'finance']);

        $reversedPasses = collect($passes)->whereIn('status', self::INVALID_PASS_STATUSES)->count();
        if ($reversedPasses) $alerts->push(['severity' => 'warning', 'title' => 'Ingressos revertidos', 'message' => "{$reversedPasses} passe(s) estão cancelados, estornados ou em chargeback.", 'module' => 'tickets']);

        $openSupport = collect($support)->whereNotIn('status', ['resolved', 'closed'])->count();
        if ($openSupport) $alerts->push(['severity' => 'warning', 'title' => 'Suporte pendente', 'message' => "{$openSupport} chamado(s) aguardam resolução.", 'module' => 'support']);

        $openModeration = collect($moderation)->where('status', 'open')->count();
        if ($openModeration) $alerts->push(['severity' => 'warning', 'title' => 'Moderação pendente', 'message' => "{$openModeration} denúncia(s) aguardam análise.", 'module' => 'moderation']);

        $openIssues = collect($issues)->whereNotIn('status', ['resolved', 'fixed', 'ignored'])->count();
        if ($openIssues) $alerts->push(['severity' => 'critical', 'title' => 'Saúde operacional', 'message' => "{$openIssues} issue(s) operacionais abertas afetam esta aplicação.", 'module' => 'health']);

        if (Schema::hasTable('interactions')) {
            $errors24h = DB::table('interactions')->where('app_id', $appId)->where('created_at', '>=', now()->subDay())
                ->where(function ($q) { $q->where('interaction_type', 'request_error')->orWhere('severity', 'error'); })->count();
            if ($errors24h) $alerts->push(['severity' => 'critical', 'title' => 'Erros nas últimas 24h', 'message' => "{$errors24h} interação(ões) de erro foram registradas.", 'module' => 'health']);
        }

        return $alerts->take(30)->values()->all();
    }

    private function map(Collection $events, Collection $productions): array
    {
        $eventPoints = $events->filter(fn ($row) => $row->latitude !== null && $row->longitude !== null)->map(fn ($row) => [
            'type' => 'event', 'id' => (int) $row->id, 'name' => $row->title, 'lat' => (float) $row->latitude,
            'lng' => (float) $row->longitude, 'city' => $row->city, 'uf' => $row->uf,
        ]);
        $productionPoints = $productions->filter(fn ($row) => $row->latitude !== null && $row->longitude !== null)->map(fn ($row) => [
            'type' => 'production', 'id' => (int) $row->id, 'name' => $row->fantasy ?: $row->name, 'lat' => (float) $row->latitude,
            'lng' => (float) $row->longitude, 'city' => $row->city, 'uf' => $row->uf,
        ]);
        return $eventPoints->concat($productionPoints)->values()->all();
    }

    private function modules(array $summary, Collection $events, array $promoters, array $campaigns, array $moderation, array $support, array $schedules, array $issues, array $audit): array
    {
        $counts = [
            'events' => $summary['events_total'], 'tickets' => $summary['ticket_types'], 'sales' => $summary['orders_total'],
            'finance' => $summary['payments_total'], 'checkins' => $summary['checkins'], 'productions' => $summary['productions_total'],
            'users' => $summary['users_total'], 'promoters' => count($promoters), 'campaigns' => count($campaigns),
            'promotions' => count($campaigns), 'catalog' => $summary['items_total'], 'social' => 0, 'moderation' => count($moderation),
            'notifications' => 0, 'communications' => count($campaigns), 'event_analytics' => $summary['events_total'],
            'app_analytics' => $summary['users_total'], 'rankings' => $summary['events_total'], 'boosts' => $summary['events_featured'],
            'schedule' => count($schedules), 'map' => $events->whereNotNull('latitude')->count(), 'audit' => count($audit),
            'health' => count($issues), 'support' => count($support), 'search' => 0, 'quick_actions' => $summary['events_total'],
            'financial_security' => 0, 'permissions' => $summary['users_total'], 'exports' => 0, 'dashboard' => 1,
        ];

        $labels = [
            'events' => 'Eventos', 'tickets' => 'Ingressos e lotes', 'sales' => 'Vendas e pedidos', 'finance' => 'Financeiro',
            'checkins' => 'Check-in e participantes', 'productions' => 'Produções', 'users' => 'Usuários', 'promoters' => 'Promoters',
            'campaigns' => 'Cupons e campanhas', 'promotions' => 'Promoções interativas', 'catalog' => 'Catálogo e adicionais',
            'social' => 'Conteúdo social', 'moderation' => 'Moderação e denúncias', 'notifications' => 'Notificações',
            'communications' => 'Comunicação', 'event_analytics' => 'Analytics do evento', 'app_analytics' => 'Analytics da aplicação',
            'rankings' => 'Ranking e inteligência comercial', 'boosts' => 'Destaques e impulsionamento', 'schedule' => 'Agenda e recorrência',
            'map' => 'Mapa operacional', 'audit' => 'Auditoria', 'health' => 'Saúde operacional', 'support' => 'Suporte',
            'search' => 'Busca global Cutinapp', 'quick_actions' => 'Ações rápidas', 'financial_security' => 'Segurança financeira',
            'permissions' => 'Permissões administrativas', 'exports' => 'Exportações', 'dashboard' => 'Dashboard Cutinapp',
        ];

        return collect($labels)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'count' => (int) ($counts[$key] ?? 0),
            'status' => 'ready',
        ])->values()->all();
    }
}
