<?php

namespace App\Models;

use App\Models\Establishment;
use App\Models\Employer;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Models\Interaction;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class Home
{
    public static function build(int $appId, array $filters = [])
    {
        $city = $filters['city'] ?? null;
        $uf = $filters['uf'] ?? null;

        $establishmentIds = Establishment::where('app_id', $appId)
            ->when(
                $city && $uf,
                fn($q) =>
                $q->where('city', $city)->where('uf', $uf)
            )
            ->pluck('id');

        return [
            'app' => [
                'id' => $appId,
                'city' => $city,
                'uf' => $uf,
            ],

            'stats' => self::getStats($appId, $establishmentIds),

            'highlights' => self::getHighlights($appId, $establishmentIds, $city, $uf),

            'establishments' => self::getEstablishments($establishmentIds),

            'employers' => self::getEmployers($establishmentIds),

            'items' => self::getItems($establishmentIds),

            'recent_orders' => self::recentOrders($appId),

            'recent_interactions' => self::recentInteractions($appId),
        ];
    }

    private static function getStats($appId, $establishmentIds)
    {
        $dau = Interaction::where('created_at', '>=', now()->startOfDay())
            ->distinct('user_id')
            ->count('user_id');

        $mau = Interaction::where('created_at', '>=', now()->subDays(30))
            ->distinct('user_id')
            ->count('user_id');

        return [
            'total_users' => User::count(),
            'total_establishments' => Establishment::where('app_id', $appId)->count(),
            'total_employers' => Employer::whereIn('establishment_id', $establishmentIds)->count(),
            'total_items' => Item::where('entity_name', 'establishment')->whereIn('entity_id', $establishmentIds)->count(),
            'total_orders' => Order::where('app_id', $appId)->count(),
            'new_users_30d' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'new_orders_30d' => Order::where('created_at', '>=', now()->subDays(30))->count(),
            'dau' => $dau,
            'mau' => $mau,
            'dau_mau_ratio' => $mau > 0 ? round($dau / $mau, 3) : 0,
        ];
    }

    private static function getHighlights($appId, $establishmentIds, $city, $uf)
    {
        $bestEstablishment = self::bestEstablishment($establishmentIds);
        $bestEmployer = self::bestEmployer($establishmentIds);
        $bestItem = self::bestItem($establishmentIds);

        $topItemWeek = Item::where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishmentIds)
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view')
                        ->where('created_at', '>=', now()->subDays(7))
            ])
            ->orderByDesc('total_views')
            ->first();

        $mostSoldMonth =
            OrderItem::whereHas(
                'order',
                fn($o) =>
                $o->where('entity_name', 'establishment')
                    ->whereIn('entity_id', $establishmentIds)
                    ->where('created_at', '>=', now()->subDays(30))
            )
                ->select('item_id', DB::raw('COUNT(*) as total'))
                ->groupBy('item_id')
                ->orderByDesc('total')
                ->with('item')
                ->first();

        return [
            'best_establishment' => $bestEstablishment,
            'best_employer' => $bestEmployer,
            'best_item' => $bestItem,
            'top_item_week' => $topItemWeek,
            'most_sold_item_month' => $mostSoldMonth,
        ];
    }

    private static function bestEstablishment($establishmentIds)
    {
        return Establishment::whereIn('id', $establishmentIds)
            ->withCount([
                'orders as completed_orders' =>
                    fn($q) => $q->whereIn('appointment_status', ['confirmed', 'attended']),

                'orders as scheduled_orders' =>
                    fn($q) => $q->where('appointment_status', 'pending'),

                'orders as cancelled_orders' =>
                    fn($q) => $q->where('appointment_status', 'cancelled'),

                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($e) {
                $conversion = $e->total_views > 0
                    ? $e->completed_orders / $e->total_views
                    : 0;

                $score =
                    $e->completed_orders * 0.45
                    + $e->scheduled_orders * 0.10
                    + ($e->cancelled_orders * -0.25)
                    + ($e->total_views * 0.10)
                    + ($conversion * 0.30);

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'score' => round($score, 2),
                ];
            })
            ->sortByDesc('score')
            ->first();
    }

    private static function bestEmployer($establishmentIds)
    {
        return Employer::whereIn('establishment_id', $establishmentIds)
            ->withCount([
                'orders as completed_orders' =>
                    fn($q) => $q->whereIn('appointment_status', ['confirmed', 'attended']),

                'orders as cancelled_orders' =>
                    fn($q) => $q->where('appointment_status', 'cancelled'),

                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($e) {
                $conversion = $e->total_views > 0
                    ? $e->completed_orders / $e->total_views
                    : 0;

                $score =
                    $e->completed_orders * 0.50
                    + ($e->cancelled_orders * -0.30)
                    + ($e->total_views * 0.10)
                    + ($conversion * 0.30);

                return [
                    'id' => $e->id,
                    'name' => $e->user->first_name . ' ' . $e->user->last_name,
                    'score' => round($score, 2),
                ];
            })
            ->sortByDesc('score')
            ->first();
    }

    private static function bestItem($establishmentIds)
    {
        return Item::where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishmentIds)
            ->withCount([
                'orderItems as sold' =>
                    fn($q) => $q->whereHas(
                        'order',
                        fn($o) => $o->whereIn('appointment_status', ['confirmed', 'attended'])
                    ),

                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($i) {
                $conversion = $i->total_views > 0
                    ? $i->sold / $i->total_views
                    : 0;

                $score =
                    $i->sold * 0.60
                    + ($i->total_views * 0.10)
                    + ($conversion * 0.30);

                return [
                    'id' => $i->id,
                    'name' => $i->name,
                    'slug' => $i->slug,
                    'type' => $i->type,
                    'score' => round($score, 2),
                ];
            })
            ->sortByDesc('score')
            ->first();
    }

    private static function getEstablishments($ids)
    {
        return Establishment::whereIn('id', $ids)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'files' => fn($q) => $q->where('entity_name', 'establishment'),
            ])
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($e) {
                $logo = $e->files->firstWhere('type', 'logo')?->public_url;
                $bg = $e->files->firstWhere('type', 'background')?->public_url;
                $gallery = $e->files->whereNotIn('type', ['logo', 'background'])
                    ->pluck('public_url')->values();

                return [
                    'id' => $e->id,
                    'type' => 'establishment',
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'city' => $e->city,
                    'uf' => $e->uf,

                    'images' => [
                        'logo' => $logo,
                        'background' => $bg,
                        'gallery' => $gallery
                    ],

                    'total_views' => $e->total_views,
                ];
            });
    }

    private static function getEmployers($establishmentIds)
    {
        return Employer::whereIn('establishment_id', $establishmentIds)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name', 'employer'),
            ])
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($emp) {
                $u = $emp->user;
                $avatar = $emp->files->firstWhere('type', 'avatar')?->public_url ?? $u->avatar;
                $gallery = $emp->files->whereNotIn('type', ['avatar'])
                    ->pluck('public_url')->values();

                return [
                    'id' => $emp->id,
                    'type' => 'employer',
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'user_name' => $u->user_name,
                    'avatar' => $avatar,
                    'gallery' => $gallery,

                    'establishment' => [
                        'name' => $emp->establishment?->name,
                        'slug' => $emp->establishment?->slug,
                        'city' => $emp->establishment?->city,
                        'uf' => $emp->establishment?->uf,
                    ],

                    'total_views' => $emp->total_views,
                ];
            });
    }

    private static function getItems($establishmentIds)
    {
        $establishments = \App\Models\Establishment::whereIn('id', $establishmentIds)
            ->get(['id', 'name', 'slug', 'city', 'uf'])
            ->keyBy('id');

        return Item::where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishmentIds)
            ->with([
                'files' => fn($q) => $q->where('entity_name', 'item'),
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($i) use ($establishments) {
                $avatar =
                    $i->files->firstWhere('is_primary', true)?->public_url
                    ?? $i->files->firstWhere('type', 'image')?->public_url
                    ?? $i->files->first()?->public_url
                    ?? null;

                $gallery = $i->files
                    ->whereNotIn('type', ['image'])
                    ->pluck('public_url')
                    ->values();

                $est = $establishments->get($i->entity_id);

                return [
                    'id' => $i->id,
                    'type' => $i->type,
                    'name' => $i->name,
                    'slug' => $i->slug,
                    'price' => $i->price,
                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $gallery,
                    ],
                    'establishment' => [
                        'name' => $est?->name,
                        'slug' => $est?->slug,
                        'city' => $est?->city,
                        'uf' => $est?->uf,
                    ],
                    'total_views' => $i->total_views,
                ];
            })
            ->values();
    }



    private static function recentOrders($appId)
    {
        return Order::where('app_id', $appId)
            ->latest()
            ->limit(20)
            ->get([
                'id',
                'order_number',
                'order_datetime',
                'customer_name',
                'total_price',
                'entity_name',
                'entity_id'
            ]);
    }

    private static function recentInteractions($appId)
    {
        return Interaction::latest()
            ->limit(20)
            ->get([
                'id',
                'entity_type',
                'entity_id',
                'interaction_type',
                'created_at',
            ]);
    }
}
