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
        $uf   = $filters['uf'] ?? null;

        $establishmentIds = Establishment::where('app_id', $appId)
            ->when($city && $uf, fn($q) =>
                $q->where('city', $city)
                  ->where('uf', $uf)
            )
            ->pluck('id');

        return [
            'app' => [
                'id'   => $appId,
                'city' => $city,
                'uf'   => $uf,
            ],

            'stats'         => self::getStats($appId, $establishmentIds),
            'highlights'    => self::getHighlights($establishmentIds, $city, $uf),
            'best_scores'   => self::getBestScores($establishmentIds),

            'establishments' => self::getEstablishments($establishmentIds),
            'employers'      => self::getEmployers($establishmentIds),
            'items'          => self::getItems($establishmentIds),

            'recent_orders'       => self::recentOrders($appId),
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

    private static function getHighlights($establishmentIds, $city, $uf)
    {
        $employerOfCity =
            Employer::withCount(['views as total_views' => fn($q) =>
                $q->where('interaction_type', 'view')
            ])
            ->when($city && $uf, fn($q) =>
                $q->whereHas('establishment', fn($qq) =>
                    $qq->where('city', $city)->where('uf', $uf)
                )
            )
            ->orderByDesc('total_views')
            ->with('user')
            ->first();

        $topItemWeek =
            Item::where('entity_name', 'establishment')
                ->whereIn('entity_id', $establishmentIds)
                ->withCount(['views as total_views' => fn($q) =>
                    $q->where('created_at', '>=', now()->subDays(7))
                      ->where('interaction_type', 'view')
                ])
                ->orderByDesc('total_views')
                ->first();

        $mostSoldMonth =
            OrderItem::whereHas('order', fn($o) =>
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
            'employer_of_the_city' => $employerOfCity,
            'top_item_week' => $topItemWeek,
            'most_sold_item_month' => $mostSoldMonth,
        ];
    }

    private static function getBestScores($establishmentIds)
    {
        return [
            'best_establishment' => self::scoreEstablishments($establishmentIds),
            'best_employer'      => self::scoreEmployers($establishmentIds),
            'best_item'          => self::scoreItems($establishmentIds),
        ];
    }

    private static function scoreEstablishments($establishmentIds)
    {
        return Establishment::whereIn('id', $establishmentIds)
            ->withCount([
                'orders as completed_orders' => fn($q) => $q->where('status', 'completed'),
                'orders as scheduled_orders' => fn($q) => $q->where('status', 'scheduled'),
                'orders as cancelled_orders' => fn($q) => $q->where('status', 'cancelled'),
                'views as total_views'       => fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($est) {
                $conversion = $est->total_views > 0 ? $est->completed_orders / $est->total_views : 0;

                $score =
                    $est->completed_orders * 0.40 +
                    $est->scheduled_orders * 0.10 +
                    ($est->cancelled_orders * -0.20) +
                    ($est->total_views * 0.10) +
                    ($conversion * 0.15);

                return [
                    'id' => $est->id,
                    'name' => $est->name,
                    'slug' => $est->slug,
                    'score' => round($score, 2),
                ];
            })
            ->sortByDesc('score')
            ->first();
    }

    private static function scoreEmployers($establishmentIds)
    {
        return Employer::whereIn('establishment_id', $establishmentIds)
            ->withCount([
                'orders as completed_orders' => fn($q) => $q->where('appointment_status', 'attended'),
                'orders as cancelled_orders' => fn($q) => $q->where('appointment_status', 'cancelled'),
                'views as total_views'       => fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($emp) {
                $conversion =
                    $emp->total_views > 0
                        ? $emp->completed_orders / $emp->total_views
                        : 0;

                $score =
                    $emp->completed_orders * 0.50 +
                    ($emp->cancelled_orders * -0.25) +
                    ($emp->total_views * 0.10) +
                    ($conversion * 0.20);

                return [
                    'id' => $emp->id,
                    'name' => $emp->user?->first_name . ' ' . $emp->user?->last_name,
                    'slug' => $emp->user?->user_name,
                    'score' => round($score, 2),
                ];
            })
            ->sortByDesc('score')
            ->first();
    }

    private static function scoreItems($establishmentIds)
    {
        return Item::where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishmentIds)
            ->withCount([
                'orderItems as sold_count' => fn($q) =>
                    $q->whereHas('order', fn($o) =>
                        $o->whereIn('appointment_status', ['confirmed', 'attended'])
                    ),
                'views as total_views' => fn($q) =>
                    $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($item) {
                $conversion =
                    $item->total_views > 0
                        ? $item->sold_count / $item->total_views
                        : 0;

                $score =
                    $item->sold_count * 0.60 +
                    ($item->total_views * 0.15) +
                    ($conversion * 0.20);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
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
                'views as total_views' => fn($q) =>
                    $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($e) {
                $logo = $e->files->firstWhere('type', 'logo')?->public_url;
                $bg = $e->files->firstWhere('type', 'background')?->public_url;
                $gallery = $e->files->whereNotIn('type', ['logo', 'background'])
                    ->pluck('public_url')
                    ->values();

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
                        'gallery' => $gallery,
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
                    ->pluck('public_url')
                    ->values();

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
        return Item::where('entity_name', 'establishment')
            ->whereIn('entity_id', $establishmentIds)
            ->with([
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name', 'item'),
            ])
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type', 'view'),
            ])
            ->get()
            ->map(function ($item) {

                $avatar = $item->files->firstWhere('type', 'image')?->public_url ?? $item->image;
                $gallery = $item->files->whereNotIn('type', ['image'])
                    ->pluck('public_url')
                    ->values();

                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $gallery,
                    ],
                    'establishment' => [
                        'name' => $item->establishment?->name,
                        'slug' => $item->establishment?->slug,
                        'city' => $item->establishment?->city,
                        'uf' => $item->establishment?->uf,
                    ],
                    'total_views' => $item->total_views,
                ];
            });
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
