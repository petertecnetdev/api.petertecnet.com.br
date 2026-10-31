<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMN = 'rent_schedule_month';
    private const INDEX = 'lease_charges_rent_month_unique';

    public function up(): void
    {
        if (! Schema::hasTable('lease_charges')) {
            return;
        }

        $expression = $this->rentMonthExpression();
        $duplicates = DB::table('lease_charges')
            ->where('type', 'rent')
            ->select('app_id', 'lease_id')
            ->selectRaw($expression.' as rent_month, COUNT(*) as total')
            ->groupBy('app_id', 'lease_id')
            ->groupByRaw($expression)
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $summary = $duplicates->map(static fn ($row) => sprintf(
                'app_id=%s, lease_id=%s, mês=%s, cobranças=%s',
                $row->app_id,
                $row->lease_id,
                $row->rent_month,
                $row->total,
            ))->implode('; ');

            throw new RuntimeException(
                'Não foi possível criar a unicidade do cronograma: existem cobranças de aluguel duplicadas. '
                .'Nenhum lançamento foi removido ou alterado. Regularize os registros listados antes de repetir a migration: '
                .$summary
            );
        }

        if (! Schema::hasColumn('lease_charges', self::COLUMN)) {
            $driver = DB::getDriverName();
            Schema::table('lease_charges', function (Blueprint $table) use ($expression, $driver) {
                $column = $table->date(self::COLUMN)->nullable();
                if ($driver === 'sqlite') {
                    $column->virtualAs($expression);
                } else {
                    $column->storedAs($expression);
                }
            });
        }

        $indexes = collect(Schema::getIndexes('lease_charges'));
        if (! $indexes->contains(static fn (array $index) => ($index['name'] ?? null) === self::INDEX)) {
            Schema::table('lease_charges', function (Blueprint $table) {
                $table->unique(['app_id', 'lease_id', self::COLUMN], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('lease_charges')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('lease_charges'));
        if ($indexes->contains(static fn (array $index) => ($index['name'] ?? null) === self::INDEX)) {
            Schema::table('lease_charges', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('lease_charges', self::COLUMN)) {
            Schema::table('lease_charges', function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }

    private function rentMonthExpression(): string
    {
        return match (DB::getDriverName()) {
            'mysql' => "CASE WHEN type = 'rent' THEN CAST(DATE_FORMAT(COALESCE(reference_date, due_date), '%Y-%m-01') AS DATE) ELSE NULL END",
            'sqlite' => "CASE WHEN type = 'rent' THEN date(COALESCE(reference_date, due_date), 'start of month') ELSE NULL END",
            'pgsql' => "CASE WHEN type = 'rent' THEN date_trunc('month', COALESCE(reference_date, due_date)::timestamp)::date ELSE NULL END",
            default => throw new RuntimeException('Driver de banco não suportado para a chave mensal de aluguel: '.DB::getDriverName()),
        };
    }
};
