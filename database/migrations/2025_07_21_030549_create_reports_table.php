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

            // Polimórfico: a entidade para a qual este relatório se aplica
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name', 100);

            $table->string('report_type', 50);
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Financeiros
            $table->decimal('cash_flow', 14, 2);
            $table->decimal('gross_profit', 14, 2);
            $table->decimal('net_profit', 14, 2);
            $table->decimal('total_expenses', 14, 2);
            $table->json('revenue_by_channel');

            // Operacionais
            $table->decimal('avg_service_time', 8, 2)->default(0);
            $table->decimal('cancellation_rate', 5, 2)->default(0);
            $table->json('peak_hours');
            $table->decimal('resource_utilization', 5, 2)->default(0);

            // Clientes
            $table->integer('new_customers_count')->default(0);
            $table->integer('returning_customers_count')->default(0);
            $table->decimal('avg_ticket_per_customer', 10, 2)->default(0);
            $table->decimal('visit_frequency', 8, 2)->default(0);
            $table->json('top_customers');

            // Itens
            $table->json('breakdown_by_item'); // { item_id => quantidade, ... }

            // Satisfação (opcional)
            $table->decimal('satisfaction_index', 5, 2)->nullable();

            // Estoque & Insumos
            $table->decimal('stock_turnover', 8, 2)->default(0);
            $table->integer('reorder_alerts_count')->default(0);
            $table->decimal('raw_material_cost', 14, 2)->default(0);

            // Equipe
            $table->json('individual_performance');
            $table->decimal('labor_efficiency', 8, 2)->default(0);
            $table->decimal('commissions_and_bonuses', 14, 2)->default(0);

            // Marketing & Vendas
            $table->decimal('campaign_roi', 8, 2)->default(0);
            $table->decimal('promotion_conversion_rate', 5, 2)->default(0);
            $table->json('lead_origin');

            // Setor‑Específicos
            $table->decimal('barbershop_completion_rate', 5, 2)->default(0);
            $table->decimal('avg_service_time_barbershop', 8, 2)->default(0);
            $table->decimal('restaurant_prep_time', 8, 2)->default(0);
            $table->decimal('table_turnover_rate', 5, 2)->default(0);
            $table->integer('legal_cases_opened_count')->default(0);
            $table->integer('legal_cases_closed_count')->default(0);
            $table->decimal('avg_legal_case_duration', 8, 2)->default(0);
            $table->decimal('hospital_bed_occupancy_rate', 5, 2)->default(0);
            $table->decimal('hospital_readmission_rate', 5, 2)->default(0);
            $table->decimal('avg_hospital_stay_duration', 8, 2)->default(0);

            // API & Infraestrutura
            $table->json('endpoint_usage');
            $table->decimal('error_rate', 5, 2)->default(0);
            $table->decimal('avg_latency', 10, 2)->default(0);
            $table->integer('auth_login_attempts_count')->default(0);
            $table->integer('auth_login_failures_count')->default(0);

            $table->timestamps();

            // índices de pesquisa
            $table->index(['entity_name', 'entity_id'], 'reports_entity_idx');
            $table->index(['report_type', 'period_start', 'period_end'], 'reports_period_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('reports');
    }
}
