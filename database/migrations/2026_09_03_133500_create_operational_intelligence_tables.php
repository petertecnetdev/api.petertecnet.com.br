<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_deployments')) {
            Schema::create('operational_deployments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('environment', 40)->default('production')->index();
                $table->string('version', 100)->nullable();
                $table->string('commit_sha', 64)->nullable()->index();
                $table->string('previous_commit_sha', 64)->nullable();
                $table->string('status', 32)->default('succeeded')->index();
                $table->string('source', 48)->default('telemetry');
                $table->timestamp('deployed_at')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'environment', 'deployed_at'], 'op_deploy_app_env_time_idx');
            });
        }

        if (! Schema::hasTable('operational_slo_definitions')) {
            Schema::create('operational_slo_definitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
                $table->string('domain', 80)->index();
                $table->decimal('availability_target', 6, 3)->default(99.900);
                $table->decimal('max_error_rate', 6, 3)->default(1.000);
                $table->unsignedInteger('p95_latency_ms')->default(1500);
                $table->unsignedInteger('window_minutes')->default(60);
                $table->boolean('enabled')->default(true)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'domain', 'enabled'], 'op_slo_app_domain_idx');
            });
        }

        if (! Schema::hasTable('operational_runbooks')) {
            Schema::create('operational_runbooks', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 120)->unique();
                $table->string('domain', 80)->nullable()->index();
                $table->string('category', 80)->nullable()->index();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->json('steps');
                $table->string('risk_level', 24)->default('low');
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_journey_definitions')) {
            Schema::create('operational_journey_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 120)->unique();
                $table->string('name', 180);
                $table->text('description')->nullable();
                $table->json('steps');
                $table->boolean('critical')->default(false)->index();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_issue_correlations')) {
            Schema::create('operational_issue_correlations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operational_issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->foreignId('related_issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->unsignedTinyInteger('score')->default(0)->index();
                $table->json('reasons')->nullable();
                $table->timestamps();
                $table->unique(['operational_issue_id', 'related_issue_id'], 'op_issue_correlation_unique');
            });
        }

        if (! Schema::hasTable('operational_alerts')) {
            Schema::create('operational_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operational_issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->string('alert_key', 160)->unique();
                $table->string('level', 24)->index();
                $table->string('status', 32)->default('open')->index();
                $table->string('reason', 255);
                $table->json('metadata')->nullable();
                $table->timestamp('first_triggered_at');
                $table->timestamp('last_triggered_at');
                $table->timestamps();
                $table->index(['operational_issue_id', 'status'], 'op_alert_issue_status_idx');
            });
        }

        if (! Schema::hasTable('operational_repair_plans')) {
            Schema::create('operational_repair_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('operational_issue_id')->constrained('operational_issues')->cascadeOnDelete();
                $table->string('status', 32)->default('suggested')->index();
                $table->string('risk_level', 24)->default('medium');
                $table->json('diagnostic_bundle');
                $table->json('candidate_files')->nullable();
                $table->json('recommended_steps');
                $table->json('validation_gates');
                $table->text('rollback_strategy')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['operational_issue_id', 'status'], 'op_repair_issue_status_idx');
            });
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_repair_plans');
        Schema::dropIfExists('operational_alerts');
        Schema::dropIfExists('operational_issue_correlations');
        Schema::dropIfExists('operational_journey_definitions');
        Schema::dropIfExists('operational_runbooks');
        Schema::dropIfExists('operational_slo_definitions');
        Schema::dropIfExists('operational_deployments');
    }

    private function seedDefaults(): void
    {
        foreach ([
            ['payments', 99.950, 0.500, 1800], ['commerce', 99.900, 1.000, 1600],
            ['scheduling', 99.900, 1.000, 1500], ['identity', 99.950, 0.500, 1200],
            ['catalog', 99.900, 1.000, 1400], ['events', 99.900, 1.000, 1600],
            ['notifications', 99.500, 2.000, 3000], ['runtime', 99.950, 0.500, 1000],
            ['platform', 99.900, 1.000, 1500],
        ] as [$domain, $availability, $errorRate, $latency]) {
            DB::table('operational_slo_definitions')->insert([
                'application_id' => null, 'domain' => $domain, 'availability_target' => $availability,
                'max_error_rate' => $errorRate, 'p95_latency_ms' => $latency, 'window_minutes' => 60,
                'enabled' => true, 'metadata' => json_encode(['source' => 'platform_default']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($this->runbooks() as $runbook) {
            DB::table('operational_runbooks')->insert([
                'slug' => $runbook['slug'], 'domain' => $runbook['domain'], 'category' => $runbook['category'],
                'title' => $runbook['title'], 'description' => $runbook['description'],
                'steps' => json_encode($runbook['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'risk_level' => $runbook['risk_level'], 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['purchase_to_fulfillment', 'Compra até retirada/entrega', ['catalog', 'commerce', 'payments', 'fulfillment'], true],
            ['service_booking', 'Agendamento de serviço', ['catalog', 'scheduling', 'notifications'], true],
            ['event_ticket_purchase', 'Compra de ingresso', ['events', 'commerce', 'payments', 'fulfillment'], true],
            ['account_access', 'Acesso à conta', ['identity', 'platform'], true],
            ['business_onboarding', 'Onboarding de estabelecimento', ['identity', 'establishments', 'catalog'], false],
        ] as [$slug, $name, $steps, $critical]) {
            DB::table('operational_journey_definitions')->insert([
                'slug' => $slug, 'name' => $name, 'description' => 'Jornada operacional genérica do ecossistema.',
                'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'critical' => $critical, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function runbooks(): array
    {
        return [
            ['slug'=>'database-failure','domain'=>null,'category'=>'database','title'=>'Falha de banco de dados','description'=>'Conectividade, migrations, locks e consultas.','risk_level'=>'medium','steps'=>['Confirmar conectividade e latência do banco.','Identificar SQLSTATE, consulta e tabela afetada.','Verificar migrations recentes e locks/deadlocks.','Executar teste direcionado antes da alteração.','Preparar rollback antes da correção.']],
            ['slug'=>'dependency-failure','domain'=>null,'category'=>'dependency','title'=>'Dependência externa indisponível','description'=>'Separa falha interna de indisponibilidade de terceiros.','risk_level'=>'low','steps'=>['Validar DNS, TLS e conectividade.','Confirmar status HTTP e timeout do provedor.','Verificar retry, circuit breaker e idempotência.','Evitar retries agressivos.','Monitorar recuperação.']],
            ['slug'=>'timeout','domain'=>null,'category'=>'timeout','title'=>'Timeout ou degradação de latência','description'=>'Investiga gargalos sem mascará-los.','risk_level'=>'medium','steps'=>['Comparar p95 atual com SLO.','Localizar operação lenta e dependências.','Verificar fila, banco e chamadas externas.','Otimizar a causa antes de elevar timeout.','Executar teste de carga direcionado.']],
            ['slug'=>'payments','domain'=>'payments','category'=>null,'title'=>'Falha no fluxo de pagamentos','description'=>'Protege idempotência, conciliação e integridade financeira.','risk_level'=>'high','steps'=>['Preservar idempotência.','Comparar pedido, ledger e estado do provedor.','Validar webhook e conciliação.','Quantificar pagamentos e valor potencialmente afetados.','Testar sem cobrança real.']],
            ['slug'=>'runtime','domain'=>'runtime','category'=>null,'title'=>'Falha de runtime, filas ou scheduler','description'=>'Recuperação controlada da infraestrutura assíncrona.','risk_level'=>'medium','steps'=>['Verificar heartbeat do scheduler.','Inspecionar jobs falhos e idade da fila.','Confirmar workers e dependências.','Reprocessar somente operações idempotentes.','Validar health check após recuperação.']],
            ['slug'=>'identity-access','domain'=>'identity','category'=>null,'title'=>'Falha de autenticação ou autorização','description'=>'Separa credencial, sessão e permissão.','risk_level'=>'medium','steps'=>['Classificar 401 e 403 separadamente.','Verificar token, sessão e aplicação de origem.','Auditar roles sem ampliar acesso.','Reproduzir com menor privilégio.','Validar login e logout após correção.']],
        ];
    }
};
