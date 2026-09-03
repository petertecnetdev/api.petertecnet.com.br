<?php

namespace App\Models;

use App\Support\TaxIdentifier;
use App\Traits\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Establishment extends Model
{
    use HasFiles, SoftDeletes;

    protected $fillable = [
        'name',
        'fantasy',
        'slug',
        'cnpj',
        'tax_id',
        'tax_id_type',
        'country_code',
        'type',
        'category',
        'phone',
        'email',
        'description',
        'additional_info',
        'city',
        'location',
        'cep',
        'address',
        'user_id',
        'updated_by',
        'created_by',
        'is_featured',
        'is_published',
        'is_approved',
        'is_cancelled',
        'website_url',
        'facebook_url',
        'instagram_url',
        'twitter_url',
        'youtube_url',
        'segments',
        'app_id',
        'uf'
    ];

    protected $casts = [
        'segments' => 'json',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'is_approved' => 'boolean',
        'is_featured' => 'boolean',
        'is_cancelled' => 'boolean',
    ];

    protected $appends = ['metrics'];

    protected $entity_name = 'establishment';

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->fantasy ?? $model->name);
            }

            $model->country_code = strtoupper(trim((string) ($model->country_code ?: 'BR')));
            $taxId = TaxIdentifier::normalizeForCountry($model->tax_id ?: $model->cnpj, $model->country_code);
            $model->tax_id = $taxId;
            $model->tax_id_type = TaxIdentifier::type($taxId, $model->country_code);

            // Compatibilidade durante a migração: integrações legadas ainda leem `cnpj`.
            if ($model->country_code === 'BR') {
                $model->cnpj = $taxId;
            }
        });
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            $model->entity_name = 'establishment';
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function app()
    {
        return $this->belongsTo(Application::class, 'app_id');
    }

    public function applications()
    {
        return $this->belongsToMany(Application::class, 'application_establishment')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function scopeForApplication($query, int $applicationId)
    {
        return $query->where(function ($applicationQuery) use ($applicationId) {
            $applicationQuery->where('app_id', $applicationId)
                ->orWhereHas('applications', fn ($related) => $related->whereKey($applicationId));
        });
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'entity_id')
            ->where('entity_name', 'establishment')
            ->withCount([
                'views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                },
                'views as unique_users' => function ($q) {
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view');
                },
            ]);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'entity_id')
            ->where('entity_name', 'establishment');
    }

    public function employers()
    {
        return $this->hasMany(Employer::class)
            ->withCount([
                'views as total_views' => function ($q) {
                    $q->where('interaction_type', 'view');
                },
                'views as unique_users' => function ($q) {
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view');
                },
                'orders as total_appointments' => function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended']);
                },
                'orders as total_revenue' => function ($q) {
                    $q->whereIn('appointment_status', ['confirmed', 'attended'])
                        ->select(\DB::raw('COALESCE(SUM(total_price),0)'));
                },
            ]);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class, 'entity_id')
            ->where('entity_type', 'Establishment');
    }

    public function views()
    {
        return $this->interactions()->where('interaction_type', 'view');
    }

    public function getMetricsAttribute()
    {
        return Cache::remember("establishment_{$this->id}_metrics", 120, function () {
            $views = $this->views();
            $items = $this->items();
            $employers = $this->employers();
            $orders = $this->orders();

            $totalViews = $views->count();
            $uniqueUsers = $views->distinct('user_id')->count('user_id');
            $totalItems = $items->count();
            $totalEmployers = $employers->count();
            $totalOrders = $orders->count();

            $completedOrders = (clone $orders)->whereIn('appointment_status', ['confirmed', 'attended'])->count();
            $cancelledOrders = (clone $orders)->whereIn('appointment_status', ['cancelled', 'rejected'])->count();
            $pendingOrders = (clone $orders)->where('appointment_status', 'pending')->count();

            $totalRevenue = (clone $orders)->sum('total_price');
            $averageTicket = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            $avgViewsPerUser = $uniqueUsers > 0 ? round($totalViews / $uniqueUsers, 2) : 0;
            $avgViewsPerItem = $totalItems > 0 ? round($totalViews / $totalItems, 2) : 0;
            $avgViewsPerEmployer = $totalEmployers > 0 ? round($totalViews / $totalEmployers, 2) : 0;

            $totalEmployerViews = \App\Models\Interaction::where('entity_type', 'Employer')
                ->whereIn('entity_id', $employers->pluck('id'))
                ->where('interaction_type', 'view')
                ->count();

            $avgEmployerViews = $totalEmployers > 0 ? round($totalEmployerViews / $totalEmployers, 2) : 0;

            $firstView = $views->min('created_at');
            if ($firstView && !($firstView instanceof \Carbon\Carbon)) {
                $firstView = Carbon::parse($firstView);
            }

            $daysActive = $firstView ? now()->diffInDays($firstView) + 1 : 1;
            $avgViewsPerDay = round($totalViews / max($daysActive, 1), 2);

            $completionRate = $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 2) : 0;
            $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 2) : 0;
            $pendingRate = $totalOrders > 0 ? round(($pendingOrders / $totalOrders) * 100, 2) : 0;
            $efficiencyRate = ($completedOrders + $cancelledOrders) > 0
                ? round(($completedOrders / ($completedOrders + $cancelledOrders)) * 100, 2)
                : 0;

            $clientsCount = (clone $orders)
                ->selectRaw('client_id, COUNT(*) as total')
                ->groupBy('client_id')
                ->pluck('total', 'client_id');

            $recurringClients = $clientsCount->filter(fn($c) => $c > 1);
            $returnRate = $clientsCount->count() > 0
                ? round(($recurringClients->count() / $clientsCount->count()) * 100, 2)
                : 0;

            $engagementScore = round(
                ($uniqueUsers * 1.5) +
                ($totalViews * 0.2) +
                ($completedOrders * 1.2) +
                ($returnRate * 0.5),
                2
            );

            $avgRevenuePerEmployer = $totalEmployers > 0 ? round($totalRevenue / $totalEmployers, 2) : 0;

            return [
                'total_items' => $totalItems,
                'total_employers' => $totalEmployers,
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'pending_orders' => $pendingOrders,
                'total_revenue' => $totalRevenue,
                'average_ticket' => $averageTicket,
                'avg_revenue_per_employer' => $avgRevenuePerEmployer,
                'total_views' => $totalViews,
                'unique_users' => $uniqueUsers,
                'avg_views_per_user' => $avgViewsPerUser,
                'avg_views_per_item' => $avgViewsPerItem,
                'avg_views_per_employer' => $avgViewsPerEmployer,
                'avg_employer_views' => $avgEmployerViews,
                'avg_views_per_day' => $avgViewsPerDay,
                'days_active' => $daysActive,
                'engagement_score' => $engagementScore,
                'completion_rate' => $completionRate,
                'cancellation_rate' => $cancellationRate,
                'pending_rate' => $pendingRate,
                'efficiency_rate' => $efficiencyRate,
                'return_rate' => $returnRate,
            ];
        });
    }
}
