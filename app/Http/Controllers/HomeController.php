<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Employer;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Models\Interaction;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function home(Request $request, $app_id)
    {
        $city = $request->query('city');
        $uf   = $request->query('uf');

        $establishmentIds = Establishment::where('app_id', $app_id)
            ->when($city && $uf, fn($q) => $q->where('city', $city)->where('uf', $uf))
            ->pluck('id');

        $establishments = $this->getEstablishments($establishmentIds);
        $employers      = $this->getEmployers($establishmentIds);
        $items          = $this->getItems($establishmentIds);
        $stats          = $this->getGlobalStats($app_id, $establishmentIds, $city, $uf);

        return response()->json([
            'establishments' => $establishments,
            'employers' => $employers,
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    private function getEstablishments($ids)
    {
        return Establishment::whereIn('id', $ids)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'files' => fn($q) => $q->where('entity_name','establishment'),
                'orders' => fn($q) =>
                    $q->whereIn('appointment_status',['confirmed','attended'])
                        ->with('client:id,first_name,last_name,user_name,avatar,email')
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type','view'),
                'views as unique_users' =>
                    fn($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)'))
                                ->where('interaction_type','view'),
                'orders as total_completed_appointments' =>
                    fn($q) => $q->whereIn('appointment_status',['confirmed','attended']),
            ])
            ->get()
            ->map(function ($e) {

                $logo    = $e->files->firstWhere('type','logo')?->public_url;
                $bg      = $e->files->firstWhere('type','background')?->public_url;
                $gallery = $e->files->whereNotIn('type',['logo','background'])
                                    ->pluck('public_url')->values();
                $owner   = $e->user;

                $topItem = Item::where('entity_name','establishment')
                    ->where('entity_id',$e->id)
                    ->withCount([
                        'orderItems as total_completed' => fn($q) =>
                            $q->whereHas('order', fn($o) =>
                                $o->whereIn('appointment_status',['confirmed','attended'])
                            )
                    ])
                    ->orderByDesc('total_completed')
                    ->first();

                $topClient = $e->orders()
                    ->selectRaw('client_id, COUNT(*) as total')
                    ->groupBy('client_id')
                    ->orderByDesc('total')
                    ->with('client')
                    ->first();

                $topEmployer = $e->employers()
                    ->withCount([
                        'orders as total_completed' =>
                            fn($q) => $q->whereIn('appointment_status',['confirmed','attended'])
                    ])
                    ->orderByDesc('total_completed')
                    ->first();

                $conversionRate =
                    $e->total_views > 0
                        ? round(($e->total_completed_appointments / $e->total_views) * 100, 2)
                        : 0;

                $ratingAvg = Interaction::where('entity_type','Establishment')
                    ->where('entity_id',$e->id)
                    ->where('interaction_type','rating')
                    ->avg('content->rating') ?? 0;

                $avgVisitTime = Interaction::where('entity_type','Establishment')
                    ->where('entity_id',$e->id)
                    ->where('interaction_type','view')
                    ->select('user_id', DB::raw('MIN(created_at) as first'), DB::raw('MAX(created_at) as last'))
                    ->groupBy('user_id')
                    ->get()
                    ->map(fn($x) => strtotime($x->last) - strtotime($x->first))
                    ->avg() ?? 0;

                $newClients30 = $e->orders()
                    ->where('created_at','>=',now()->subDays(30))
                    ->select('client_id')
                    ->distinct()
                    ->count();

                $returning30 = $e->orders()
                    ->where('created_at','>=',now()->subDays(30))
                    ->select('client_id')
                    ->groupBy('client_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->count();

                $peakHour = $e->orders()
                    ->select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as total'))
                    ->groupBy('hour')
                    ->orderByDesc('total')
                    ->first();

                return [
                    'id' => $e->id,
                    'type' => 'establishment',
                    'name' => $e->name,
                    'slug' => $e->slug,
                    'city' => $e->city,
                    'uf'   => $e->uf,

                    'owner' => $owner ? [
                        'id' => $owner->id,
                        'name' => trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? '')),
                        'user_name' => $owner->user_name,
                        'avatar' => $owner->avatar,
                    ] : null,

                    'images' => [
                        'logo' => $logo,
                        'background' => $bg,
                        'gallery' => $gallery,
                    ],

                    'total_views' => $e->total_views,
                    'unique_users' => $e->unique_users,
                    'completed_appointments' => $e->total_completed_appointments,

                    'conversion_rate' => $conversionRate,
                    'average_rating' => round($ratingAvg,2),
                    'average_time_between_visits_seconds' => $avgVisitTime,
                    'new_clients_30d' => $newClients30,
                    'returning_clients_30d' => $returning30,
                    'peak_hour' => $peakHour?->hour,

                    'top_item' => $topItem,
                    'top_client' => $topClient,
                    'top_employer' => $topEmployer,

                    'other_establishments' =>
                        Establishment::where('id','!=',$e->id)->limit(10)->get(['id','name','slug'])
                ];
            });
    }

    private function getEmployers($establishmentIds)
    {
        return Employer::whereIn('establishment_id', $establishmentIds)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email',
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name','employer'),
                'orders' => fn($q) =>
                    $q->whereIn('appointment_status',['confirmed','attended'])
                        ->with('client:id,first_name,last_name,user_name,avatar,email')
            ])
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type','view'),
                'orders as total_completed_appointments' =>
                    fn($q) => $q->whereIn('appointment_status',['confirmed','attended'])
            ])
            ->get()
            ->map(function ($emp) {

                $u = $emp->user;
                $avatar = $emp->files->firstWhere('type','avatar')?->public_url ?? $u->avatar;
                $gallery = $emp->files->whereNotIn('type',['avatar'])->pluck('public_url')->values();

                $orders = $emp->orders()->count();

                $confirmationRate =
                    $orders > 0
                        ? round(($emp->orders()->where('appointment_status','confirmed')->count() / $orders) * 100,2)
                        : 0;

                $retentionRate =
                    $emp->orders()
                        ->select('client_id')
                        ->groupBy('client_id')
                        ->havingRaw('COUNT(*) > 1')
                        ->count();

                $social =
                    Interaction::where('entity_type','Employer')
                        ->where('entity_id',$emp->id)
                        ->whereIn('interaction_type',['like','share','comment'])
                        ->count();

                $mostProfitable =
                    OrderItem::whereHas('order', fn($o) =>
                        $o->where('attendant_id',$emp->id)
                          ->whereIn('appointment_status',['confirmed','attended'])
                    )
                    ->select('item_id', DB::raw('SUM(subtotal) as total'))
                    ->groupBy('item_id')
                    ->orderByDesc('total')
                    ->with('item')
                    ->first();

                $avgRealTime =
                    $emp->orders()
                        ->whereNotNull('attended_at')
                        ->get()
                        ->map(fn($x) => strtotime($x->attended_at) - strtotime($x->order_datetime))
                        ->avg() ?? 0;

                $colleagues =
                    Employer::where('establishment_id',$emp->establishment_id)
                        ->where('id','!=',$emp->id)
                        ->withCount([
                            'views as engagement' =>
                                fn($q) => $q->where('interaction_type','view')
                        ])
                        ->orderByDesc('engagement')
                        ->limit(10)
                        ->get();

                $topItem =
                    OrderItem::whereHas('order', fn($o) =>
                        $o->where('attendant_id',$emp->id)
                          ->whereIn('appointment_status',['confirmed','attended'])
                    )
                    ->select('item_id', DB::raw('COUNT(*) as total'))
                    ->with('item:id,name,slug')
                    ->groupBy('item_id')
                    ->orderByDesc('total')
                    ->first();

                $topItemClient = null;

                if ($topItem) {
                    $topItemClient =
                        OrderItem::where('item_id', $topItem->item_id)
                            ->whereHas('order', fn($o) =>
                                $o->where('attendant_id',$emp->id)
                                  ->whereIn('appointment_status',['confirmed','attended'])
                            )
                            ->select(DB::raw('client_id, COUNT(*) as total'))
                            ->groupBy('client_id')
                            ->orderByDesc('total')
                            ->with('client')
                            ->first();
                }

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
                    'completed_appointments' => $emp->total_completed_appointments,

                    'confirmation_rate' => $confirmationRate,
                    'retention_rate' => $retentionRate,
                    'social_engagement' => $social,
                    'most_profitable_service' => $mostProfitable,
                    'average_real_service_time_seconds' => $avgRealTime,

                    'top_item' => $topItem,

                    'top_item_client' => $topItemClient ? [
                        'id' => $topItemClient->client?->id,
                        'name' => trim(($topItemClient->client?->first_name ?? '') . ' ' . ($topItemClient->client?->last_name ?? '')),
                        'avatar' => $topItemClient->client?->avatar,
                        'total' => $topItemClient->total,
                    ] : null,

                    'colleagues' => $colleagues,

                    'other_employers' =>
                        Employer::where('id','!=',$emp->id)->limit(10)->get(['id','user_id'])
                ];
            });
    }

    private function getItems($establishmentIds)
    {
        return Item::where('entity_name','establishment')
            ->whereIn('entity_id',$establishmentIds)
            ->with([
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name','item'),
                'orderItems.order.client'
            ])
            ->withCount([
                'views as total_views' =>
                    fn($q) => $q->where('interaction_type','view'),
                'orderItems as total_completed_appointments' =>
                    fn($q) =>
                        $q->whereHas('order', fn($o) =>
                            $o->whereIn('appointment_status',['confirmed','attended'])
                        ),
            ])
            ->get()
            ->map(function ($item) {

                $avatar = $item->files->firstWhere('type','image')?->public_url ?? $item->image;
                $gallery = $item->files->whereNotIn('type',['image'])->pluck('public_url')->values();

                $uniqueClients =
                    OrderItem::where('item_id',$item->id)
                        ->whereHas('order', fn($o) =>
                            $o->whereIn('appointment_status',['confirmed','attended'])
                        )
                        ->with('order:id,client_id')
                        ->get()
                        ->pluck('order.client_id')
                        ->filter()
                        ->unique()
                        ->count();

                $conversionRate =
                    $item->total_views > 0
                        ? round(($item->total_completed_appointments / $item->total_views) * 100, 2)
                        : 0;

                $avgTimeToOrder =
                    Interaction::where('entity_type','Item')
                        ->where('entity_id',$item->id)
                        ->where('interaction_type','view')
                        ->get()
                        ->map(function ($view) use ($item) {
                            $order =
                                OrderItem::where('item_id',$item->id)
                                    ->whereHas('order', fn($o) =>
                                        $o->where('client_id',$view->user_id)
                                          ->whereIn('appointment_status',['confirmed','attended'])
                                    )
                                    ->orderBy('id','asc')
                                    ->first();
                            return $order ? (strtotime($order->order->created_at) - strtotime($view->created_at)) : null;
                        })
                        ->filter()
                        ->avg() ?? 0;

                $totalRevenue =
                    OrderItem::where('item_id',$item->id)->sum('subtotal');

                $clientFrequency =
                    OrderItem::where('item_id',$item->id)
                        ->select('client_id', DB::raw('COUNT(*) as total'))
                        ->groupBy('client_id')
                        ->orderByDesc('total')
                        ->get();

                $peakHour =
                    OrderItem::where('item_id',$item->id)
                        ->whereHas('order', fn($o) =>
                            $o->whereIn('appointment_status',['confirmed','attended'])
                        )
                        ->select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as total'))
                        ->groupBy('hour')
                        ->orderByDesc('total')
                        ->first();

                $topEmployer =
                    OrderItem::where('item_id',$item->id)
                        ->whereHas('order', fn($o) =>
                            $o->whereIn('appointment_status',['confirmed','attended'])
                        )
                        ->select('order_id')
                        ->with('order.attendant.user')
                        ->get()
                        ->groupBy(fn($x) => $x->order->attendant_id)
                        ->map->count()
                        ->sortDesc()
                        ->first();

                $topClient =
                    OrderItem::where('item_id',$item->id)
                        ->whereHas('order', fn($o) =>
                            $o->whereIn('appointment_status',['confirmed','attended'])
                        )
                        ->select(DB::raw('client_id, COUNT(*) as total'))
                        ->groupBy('client_id')
                        ->orderByDesc('total')
                        ->with('order.client')
                        ->first();

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
                    'completed_appointments' => $item->total_completed_appointments,
                    'unique_clients_attended' => $uniqueClients,

                    'item_conversion_rate' => $conversionRate,
                    'average_time_to_order_seconds' => $avgTimeToOrder,
                    'total_revenue' => $totalRevenue,
                    'client_frequency' => $clientFrequency,
                    'peak_item_hour' => $peakHour?->hour,

                    'top_employer' =>
                        $topEmployer ? [
                            'employer_id' => array_key_first($topEmployer),
                            'total' => $topEmployer
                        ] : null,

                    'top_client' => $topClient,

                    'other_items' =>
                        Item::where('id','!=',$item->id)->limit(10)->get(['id','name','slug'])
                ];
            });
    }

    private function getGlobalStats($appId, $establishmentIds, $city, $uf)
    {
        $dau = Interaction::where('created_at','>=',now()->startOfDay())
                ->distinct('user_id')
                ->count('user_id');

        $mau = Interaction::where('created_at','>=',now()->subDays(30))
                ->distinct('user_id')
                ->count('user_id');

        return [
            'total_users' => User::count(),
            'total_establishments' => Establishment::where('app_id',$appId)->count(),
            'total_employers' => Employer::whereIn('establishment_id',$establishmentIds)->count(),
            'total_items' => Item::where('entity_name','establishment')->whereIn('entity_id',$establishmentIds)->count(),
            'total_orders' => Order::where('app_id',$appId)->count(),
            'total_interactions' => Interaction::count(),

            'total_cities' =>
                Establishment::where('app_id',$appId)->distinct('city')->count('city'),

            'users_growth_30d' =>
                User::where('created_at','>=',now()->subDays(30))->count(),

            'orders_growth_30d' =>
                Order::where('created_at','>=',now()->subDays(30))->count(),

            'new_establishments_month' =>
                Establishment::where('created_at','>=',now()->subDays(30))->count(),

            'avg_time_to_conversion' =>
                Interaction::where('interaction_type','view')
                    ->get()
                    ->map(function ($i) {
                        $o = Order::where('client_id',$i->user_id)
                            ->where('created_at','>', $i->created_at)
                            ->orderBy('created_at','asc')
                            ->first();
                        return $o ? (strtotime($o->created_at) - strtotime($i->created_at)) : null;
                    })->filter()->avg() ?? 0,

            'global_top_client' =>
                Interaction::select('user_id', DB::raw('COUNT(*) as total'))
                    ->groupBy('user_id')
                    ->orderByDesc('total')
                    ->with('user')
                    ->first(),

            'most_popular_items' =>
                Item::withCount([
                    'orderItems as completed' =>
                        fn($q) =>
                            $q->whereHas('order', fn($o) =>
                                $o->whereIn('appointment_status',['confirmed','attended'])
                            )
                ])
                ->orderByDesc('completed')
                ->limit(10)
                ->get(),

            'barber_of_the_city' =>
                Employer::withCount([
                    'views as total_views' =>
                        fn($q) => $q->where('interaction_type','view')
                ])
                ->when($city && $uf, fn($q) =>
                    $q->whereHas('establishment', fn($qq) =>
                        $qq->where('city',$city)->where('uf',$uf)
                    )
                )
                ->orderByDesc('total_views')
                ->with('user')
                ->first(),

            'barber_most_profitable' =>
                OrderItem::select('attendant_id', DB::raw('SUM(subtotal) as total'))
                    ->join('orders','orders.id','=','order_items.order_id')
                    ->groupBy('attendant_id')
                    ->orderByDesc('total')
                    ->with('order.attendant.user')
                    ->first(),

            'top_viewed_establishments' =>
                Establishment::withCount([
                    'views as total_views' =>
                        fn($q) => $q->where('interaction_type','view')
                ])
                ->orderByDesc('total_views')
                ->limit(10)
                ->get(),

            'top_viewed_employers' =>
                Employer::withCount([
                    'views as total_views' =>
                        fn($q) => $q->where('interaction_type','view')
                ])
                ->orderByDesc('total_views')
                ->limit(10)
                ->get(),

            'top_viewed_items' =>
                Item::withCount([
                    'views as total_views' =>
                        fn($q) => $q->where('interaction_type','view')
                ])
                ->orderByDesc('total_views')
                ->limit(10)
                ->get(),

            'top_item_week' =>
                Item::withCount([
                    'views as total_views' =>
                        fn($q) =>
                            $q->where('created_at','>=',now()->subDays(7))
                              ->where('interaction_type','view')
                ])
                ->orderByDesc('total_views')
                ->first(),

            'most_sold_item_month' =>
                OrderItem::whereHas('order', fn($o) =>
                        $o->where('created_at','>=',now()->subDays(30))
                    )
                    ->select('item_id', DB::raw('COUNT(*) as total'))
                    ->groupBy('item_id')
                    ->orderByDesc('total')
                    ->with('item')
                    ->first(),

            'retention_rate_global' =>
                Order::select('client_id')
                    ->groupBy('client_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->count(),

            'dau' => $dau,
            'mau' => $mau,
            'dau_mau_ratio' =>
                $mau > 0 ? round($dau / $mau, 3) : 0,

            'global_peak_hours' =>
                Order::select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as total'))
                    ->groupBy('hour')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->get(),
        ];
    }
}
