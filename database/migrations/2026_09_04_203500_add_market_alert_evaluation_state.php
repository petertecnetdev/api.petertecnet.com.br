<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('market_alerts')) {
            return;
        }

        Schema::table('market_alerts', function (Blueprint $table) {
            if (! Schema::hasColumn('market_alerts', 'is_triggered')) {
                $table->boolean('is_triggered')->default(false)->after('active');
            }
            if (! Schema::hasColumn('market_alerts', 'last_value')) {
                $table->decimal('last_value', 30, 8)->nullable()->after('is_triggered');
            }
            if (! Schema::hasColumn('market_alerts', 'last_evaluated_at')) {
                $table->timestamp('last_evaluated_at')->nullable()->after('last_value');
            }
            if (! Schema::hasColumn('market_alerts', 'trigger_count')) {
                $table->unsignedInteger('trigger_count')->default(0)->after('last_triggered_at');
            }
        });

        if (Schema::hasColumn('market_alerts', 'active') && Schema::hasColumn('market_alerts', 'is_triggered')) {
            Schema::table('market_alerts', function (Blueprint $table) {
                $table->index(['active', 'is_triggered'], 'market_alerts_active_triggered_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('market_alerts')) {
            return;
        }

        Schema::table('market_alerts', function (Blueprint $table) {
            try {
                $table->dropIndex('market_alerts_active_triggered_idx');
            } catch (Throwable) {
                // Index may not exist on partially applied environments.
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('market_alerts', 'is_triggered') ? 'is_triggered' : null,
                Schema::hasColumn('market_alerts', 'last_value') ? 'last_value' : null,
                Schema::hasColumn('market_alerts', 'last_evaluated_at') ? 'last_evaluated_at' : null,
                Schema::hasColumn('market_alerts', 'trigger_count') ? 'trigger_count' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
