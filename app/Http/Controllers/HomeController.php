<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Employer;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;
use App\Models\Interaction;
use Illuminate\Http\Request;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function home(Request $request, $app_id)
    {
        $city = $request->query('city');
        $uf   = $request->query('uf');

        $establishments = Establishment::where('app_id', $app_id)
            ->when($city && $uf, fn($q) => $q->where('city', $city)->where('uf', $uf))
            ->with([
                'files' => fn($q) => $q->where('entity_name', 'establishment')
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
                'views as unique_users' => fn($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view'),
                'orders as total_completed_appointments' => fn($q) => $q->whereIn('appointment_status', ['confirmed', 'attended']),
            ])
            ->get()
            ->map(function ($e) {
                $logo = $e->files->firstWhere('type', 'logo')?->public_url;
                $bg   = $e->files->firstWhere('type', 'background')?->public_url;
                $gallery = $e->files->whereNotIn('type', ['logo','background'])->pluck('public_url')->values();

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
                    'unique_users' => $e->unique_users,
                    'completed_appointments' => $e->total_completed_appointments,
                ];
            });

        $establishmentIds = $establishments->pluck('id');

        $employers = Employer::whereIn('establishment_id', $establishmentIds)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email,city,uf',
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name', 'employer'),
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
                'views as unique_users' => fn($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view'),
                'orders as total_completed_appointments' => fn($q) => $q->whereIn('appointment_status', ['confirmed','attended']),
            ])
            ->orderByDesc('total_completed_appointments')
            ->get()
            ->map(function ($emp) {
                $u = $emp->user;
                $avatar = $emp->files->firstWhere('type','avatar')?->public_url ?? $u->avatar;
                $gallery = $emp->files->whereNotIn('type',['avatar'])->pluck('public_url')->values();

                return [
                    'id' => $emp->id,
                    'type' => 'employer',
                    'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')),
                    'slug' => $u->user_name,
                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $gallery,
                    ],
                    'city' => $emp->establishment?->city,
                    'uf' => $emp->establishment?->uf,
                    'total_views' => $emp->total_views,
                    'unique_users' => $emp->unique_users,
                    'completed_appointments' => $emp->total_completed_appointments,
                    'establishment' => [
                        'name' => $emp->establishment?->name,
                        'slug' => $emp->establishment?->slug,
                    ]
                ];
            });

        $items = Item::whereIn('entity_id', $establishmentIds)
            ->where('entity_name', 'establishment')
            ->with([
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name','item'),
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type','view'),
                'views as unique_users' => fn($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type','view'),
                'orderItems as total_completed_appointments' => fn($q) => $q->whereHas('order', fn($o) => $o->whereIn('appointment_status',['confirmed','attended'])),
            ])
            ->orderByDesc('total_completed_appointments')
            ->get()
            ->map(function ($item) {
                $avatar = $item->files->firstWhere('type','image')?->public_url ?? asset('images/logo.png');
                $gallery = $item->files->whereNotIn('type',['image'])->pluck('public_url')->values();

                $uniqueClients = OrderItem::where('item_id',$item->id)
                    ->whereHas('order', fn($o) => $o->whereIn('appointment_status',['confirmed','attended']))
                    ->with('order:id,client_id')
                    ->get()
                    ->pluck('order.client_id')
                    ->filter()
                    ->unique()
                    ->count();

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
                    'city' => $item->establishment?->city,
                    'uf'   => $item->establishment?->uf,
                    'total_views' => $item->total_views,
                    'unique_users' => $item->unique_users,
                    'unique_clients_attended' => $uniqueClients,
                    'completed_appointments' => $item->total_completed_appointments,
                    'establishment' => [
                        'name' => $item->establishment?->name,
                        'slug' => $item->establishment?->slug,
                    ]
                ];
            });

        $stats = [
            'total_users' => User::count(),
            'total_establishments' => Establishment::where('app_id',$app_id)->count(),
            'total_employers' => Employer::whereIn('establishment_id',$establishmentIds)->count(),
            'total_items' => Item::whereIn('entity_id',$establishmentIds)->count(),
            'total_orders' => Order::where('app_id',$app_id)->count(),
            'total_interactions' => Interaction::count(),
            'top_viewed_establishments' => $establishments->sortByDesc('total_views')->take(5)->values(),
            'top_viewed_employers' => $employers->sortByDesc('total_views')->take(5)->values(),
            'top_viewed_items' => $items->sortByDesc('total_views')->take(5)->values(),
        ];

        return response()->json([
            'establishments' => $establishments,
            'employers' => $employers,
            'items' => $items,
            'stats' => $stats,
        ]);
    }
}
