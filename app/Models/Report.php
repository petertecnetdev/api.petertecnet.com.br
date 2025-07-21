<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Item;
use App\Models\User;

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
        'top_customers',
        'breakdown_by_item',
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
    ];

    protected $casts = [
        'entity_id'                    => 'integer',
        'period_start'                 => 'datetime',
        'period_end'                   => 'datetime',
        'cash_flow'                    => 'decimal:2',
        'gross_profit'                 => 'decimal:2',
        'net_profit'                   => 'decimal:2',
        'total_expenses'               => 'decimal:2',
        'revenue_by_channel'           => 'array',
        'avg_service_time'             => 'decimal:2',
        'cancellation_rate'            => 'decimal:2',
        'peak_hours'                   => 'array',
        'new_customers_count'          => 'integer',
        'returning_customers_count'    => 'integer',
        'avg_ticket_per_customer'      => 'decimal:2',
        'visit_frequency'              => 'decimal:2',
        'top_customers'                => 'array',
        'breakdown_by_item'            => 'array',
        'satisfaction_index'           => 'decimal:2',
        'stock_turnover'               => 'decimal:2',
        'reorder_alerts_count'         => 'integer',
        'raw_material_cost'            => 'decimal:2',
        'individual_performance'       => 'array',
        'endpoint_usage'               => 'array',
    ];

    protected $appends = [
        'items_detail',
        'top_customers_detail',
    ];

    /**
     * Accessor: detalha os itens vendidos.
     *
     * Retorna um array de:
     *   [
     *     'item_id'   => int,
     *     'item_name' => string,
     *     'quantity'  => int,
     *   ]
     */
    public function getItemsDetailAttribute(): array
    {
        return collect($this->breakdown_by_item)
            ->map(function (array $entry) {
                return [
                    'item_id'   => $entry['item_id'],
                    'item_name' => $entry['item_name'] ?? Item::find($entry['item_id'])?->name ?? '-',
                    'quantity'  => (int) $entry['quantity'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Accessor: detalha os top clientes.
     *
     * Retorna um array de:
     *   [
     *     'customer_id'   => int,
     *     'customer_name' => string,
     *     'orders_count'  => int,
     *     'total_spent'   => float,
     *   ]
     */
    public function getTopCustomersDetailAttribute(): array
    {
        return collect($this->top_customers)
            ->map(function (array $entry) {
                return [
                    'customer_id'   => $entry['customer_id'],
                    'customer_name' => $entry['customer_name'] ?? (User::find($entry['customer_id'])?->name ?? '-'),
                    'orders_count'  => (int) $entry['orders_count'],
                    'total_spent'   => (float) $entry['total_spent'],
                ];
            })
            ->values()
            ->all();
    }
}
