<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Item;
use App\Models\User;
use App\Models\Order;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'entity_name',
        'report_type',
        'period_start',
        'period_end',
        'cash_flow',
        'gross_profit',
        'net_profit',
        'total_expenses',
        'revenue_by_channel',
        'avg_service_time',
        'cancellation_rate',
        'peak_hours',
        'resource_utilization',
        'new_customers_count',
        'returning_customers_count',
        'avg_ticket_per_customer',
        'visit_frequency',
        'satisfaction_index',
        'stock_turnover',
        'reorder_alerts_count',
        'raw_material_cost',
        'individual_performance',
        'labor_efficiency',
        'commissions_and_bonuses',
        'campaign_roi',
        'promotion_conversion_rate',
        'lead_origin',
        'barbershop_completion_rate',
        'avg_service_time_barbershop',
        'restaurant_prep_time',
        'table_turnover_rate',
        'legal_cases_opened_count',
        'legal_cases_closed_count',
        'avg_legal_case_duration',
        'hospital_bed_occupancy_rate',
        'hospital_readmission_rate',
        'avg_hospital_stay_duration',
        'endpoint_usage',
        'error_rate',
        'avg_latency',
        'auth_login_attempts_count',
        'auth_login_failures_count',
        'breakdown_by_item',
        'top_customers',
    ];

    protected $casts = [
        'entity_id'                     => 'integer',
        'entity_name'                   => 'string',
        'period_start'                  => 'datetime',
        'period_end'                    => 'datetime',
        'cash_flow'                     => 'decimal:2',
        'gross_profit'                  => 'decimal:2',
        'net_profit'                    => 'decimal:2',
        'total_expenses'                => 'decimal:2',
        'revenue_by_channel'            => 'array',
        'avg_service_time'              => 'decimal:2',
        'cancellation_rate'             => 'decimal:2',
        'peak_hours'                    => 'array',
        'resource_utilization'          => 'decimal:2',
        'new_customers_count'           => 'integer',
        'returning_customers_count'     => 'integer',
        'avg_ticket_per_customer'       => 'decimal:2',
        'visit_frequency'               => 'decimal:2',
        'satisfaction_index'            => 'decimal:2',
        'stock_turnover'                => 'decimal:2',
        'reorder_alerts_count'          => 'integer',
        'raw_material_cost'             => 'decimal:2',
        'individual_performance'        => 'array',
        'labor_efficiency'              => 'decimal:2',
        'commissions_and_bonuses'       => 'decimal:2',
        'campaign_roi'                  => 'decimal:2',
        'promotion_conversion_rate'     => 'decimal:2',
        'lead_origin'                   => 'array',
        'barbershop_completion_rate'    => 'decimal:2',
        'avg_service_time_barbershop'   => 'decimal:2',
        'restaurant_prep_time'          => 'decimal:2',
        'table_turnover_rate'           => 'decimal:2',
        'legal_cases_opened_count'      => 'integer',
        'legal_cases_closed_count'      => 'integer',
        'avg_legal_case_duration'       => 'decimal:2',
        'hospital_bed_occupancy_rate'   => 'decimal:2',
        'hospital_readmission_rate'     => 'decimal:2',
        'avg_hospital_stay_duration'    => 'decimal:2',
        'endpoint_usage'                => 'array',
        'error_rate'                    => 'decimal:2',
        'avg_latency'                   => 'decimal:2',
        'auth_login_attempts_count'     => 'integer',
        'auth_login_failures_count'     => 'integer',
        'breakdown_by_item'             => 'array',
        'top_customers'                 => 'array',
    ];

    protected $appends = [
        'items_detail',
        'top_customers_detail',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_name', 'entity_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'entity_id', 'entity_id')
                    ->where('entity_name', $this->entity_name)
                    ->whereBetween('order_datetime', [$this->period_start, $this->period_end]);
    }

    public function getItemsDetailAttribute()
    {
        return collect($this->breakdown_by_item)
            ->map(fn(int $quantity, $itemId) => [
                'item_id'   => $itemId,
                'item_name' => optional(Item::find($itemId))->name ?? "Item #{$itemId}",
                'quantity'  => $quantity,
            ])
            ->values()
            ->all();
    }

    public function getTopCustomersDetailAttribute()
    {
        return collect($this->top_customers)
            ->map(fn(array $entry) => [
                'client_id'    => $entry['client_id'],
                'client_name'  => optional(User::find($entry['client_id']))->first_name 
                                  . ' ' 
                                  . optional(User::find($entry['client_id']))->last_name
                                  ?? "Cliente #{$entry['client_id']}",
                'orders_count' => $entry['orders_count'],
            ])
            ->values()
            ->all();
    }
}
