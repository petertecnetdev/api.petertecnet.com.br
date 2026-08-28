<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('reports')) {
            return;
        }

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('entity_name', 100)->index();
            $table->string('report_type', 100)->index();
            $table->dateTime('period_start')->nullable()->index();
            $table->dateTime('period_end')->nullable()->index();

            $table->decimal('cash_flow', 14, 2)->default(0);
            $table->decimal('gross_profit', 14, 2)->default(0);
            $table->decimal('net_profit', 14, 2)->default(0);
            $table->decimal('total_expenses', 14, 2)->default(0);
            $table->json('revenue_by_channel')->nullable();

            $table->decimal('avg_service_time', 10, 2)->default(0);
            $table->decimal('cancellation_rate', 6, 2)->default(0);
            $table->json('peak_hours')->nullable();
            $table->decimal('resource_utilization', 6, 2)->default(0);

            $table->unsignedInteger('new_customers_count')->default(0);
            $table->unsignedInteger('returning_customers_count')->default(0);
            $table->decimal('avg_ticket_per_customer', 14, 2)->default(0);
            $table->decimal('visit_frequency', 10, 2)->default(0);
            $table->json('top_customers')->nullable();

            $table->json('breakdown_by_item')->nullable();
            $table->json('individual_performance')->nullable();
            $table->json('lead_origin')->nullable();
            $table->json('endpoint_usage')->nullable();
            $table->timestamps();

            $table->index(['entity_name', 'entity_id', 'report_type'], 'reports_entity_type_idx');
        });
    }

    public function down(): void
    {
        // Non-destructive by design: production installations may have a legacy reports table.
    }
};
