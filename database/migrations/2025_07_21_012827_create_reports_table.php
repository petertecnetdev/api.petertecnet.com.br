<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportsTable extends Migration
{
    public function up()
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('cash_flow', 14, 2);
            $table->decimal('gross_profit', 14, 2);
            $table->decimal('net_profit', 14, 2);
            $table->decimal('total_expenses', 14, 2);
            $table->json('revenue_by_channel');
            $table->decimal('avg_service_time', 8, 2);
            $table->decimal('cancellation_rate', 5, 2);
            $table->json('peak_hours');
            $table->decimal('resource_utilization', 5, 2);
            $table->integer('new_customers_count');
            $table->integer('returning_customers_count');
            $table->decimal('avg_ticket_per_customer', 10, 2);
            $table->decimal('visit_frequency', 8, 2);
            $table->decimal('satisfaction_index', 5, 2)->nullable();
            $table->decimal('stock_turnover', 8, 2);
            $table->integer('reorder_alerts_count');
            $table->decimal('raw_material_cost', 14, 2);
            $table->json('individual_performance');
            $table->decimal('labor_efficiency', 8, 2);
            $table->decimal('commissions_and_bonuses', 14, 2);
            $table->decimal('campaign_roi', 8, 2);
            $table->decimal('promotion_conversion_rate', 5, 2);
            $table->json('lead_origin');
            $table->decimal('barbershop_completion_rate', 5, 2);
            $table->decimal('avg_service_time_barbershop', 8, 2);
            $table->decimal('restaurant_prep_time', 8, 2);
            $table->decimal('table_turnover_rate', 5, 2);
            $table->integer('legal_cases_opened_count');
            $table->integer('legal_cases_closed_count');
            $table->decimal('avg_legal_case_duration', 8, 2);
            $table->decimal('hospital_bed_occupancy_rate', 5, 2);
            $table->decimal('hospital_readmission_rate', 5, 2);
            $table->decimal('avg_hospital_stay_duration', 8, 2);
            $table->json('endpoint_usage');
            $table->decimal('error_rate', 5, 2);
            $table->decimal('avg_latency', 10, 2);
            $table->integer('auth_login_attempts_count');
            $table->integer('auth_login_failures_count');
            $table->timestamps();
            $table->index(['report_type', 'period_start', 'period_end']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('reports');
    }
}
