<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderForecast extends Model
{
    protected $table = 'order_forecasts';

    protected $fillable = [
        'forecast_date',
        'forecast_time',
        'entity_id',
        'entity_name',
        'customer_name_forecast',
        'origin_forecast',
        'fulfillment_forecast',
        'items_forecast',
        'total_forecast',
        'payment_method_forecast',
        'notes_forecast',
        'order_id',
        'order_datetime_real',
        'customer_name_real',
        'origin_real',
        'fulfillment_real',
        'items_real',
        'total_real',
        'payment_method_real',
        'notes_real',
        'hit_customer_name',
        'hit_origin',
        'hit_fulfillment',
        'hit_items',
        'hit_payment_method',
        'hit_notes',
        'accuracy_value',
        'diff_total',
        'score',
        'input_data',
        'status',
        'user_id',
        'human_evaluation',
        'human_feedback',
        'probability_of_approval',
        'model_confidence',
        'reason_for_prediction',
        'historical_similarity',
        'is_recommended',
        'is_improbable',
        'repeat_forecast_count',
        'operational_feedback',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'forecast_time' => 'string',
        'entity_id' => 'integer',
        'entity_name' => 'string',
        'order_datetime_real' => 'datetime',
        'items_forecast' => 'array',
        'items_real' => 'array',
        'hit_customer_name' => 'boolean',
        'hit_origin' => 'boolean',
        'hit_fulfillment' => 'boolean',
        'hit_items' => 'boolean',
        'hit_payment_method' => 'boolean',
        'hit_notes' => 'boolean',
        'accuracy_value' => 'decimal:2',
        'diff_total' => 'decimal:2',
        'total_forecast' => 'decimal:2',
        'total_real' => 'decimal:2',
        'score' => 'integer',
        'input_data' => 'array',
        'probability_of_approval' => 'decimal:2',
        'model_confidence' => 'decimal:2',
        'historical_similarity' => 'integer',
        'is_recommended' => 'boolean',
        'is_improbable' => 'boolean',
        'repeat_forecast_count' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
