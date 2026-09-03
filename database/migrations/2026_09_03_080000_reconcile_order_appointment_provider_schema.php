<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'attendant_id')) {
            return;
        }

        $this->dropAttendantForeignKey();

        if (Schema::hasTable('employers')) {
            // The application model and appointment flows have always treated
            // orders.attendant_id as an Employer id. Older installations created
            // the FK against users, so normalize any user-based legacy values
            // before enforcing the canonical relation.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement(<<<'SQL'
                    UPDATE orders o
                    LEFT JOIN employers by_id
                        ON by_id.id = o.attendant_id
                    LEFT JOIN employers by_user
                        ON by_user.user_id = o.attendant_id
                       AND o.entity_name = 'establishment'
                       AND by_user.establishment_id = o.entity_id
                    SET o.attendant_id = CASE
                        WHEN by_id.id IS NOT NULL THEN by_id.id
                        WHEN by_user.id IS NOT NULL THEN by_user.id
                        ELSE NULL
                    END
                    WHERE o.attendant_id IS NOT NULL
                SQL);
            } else {
                DB::table('orders')
                    ->whereNotNull('attendant_id')
                    ->orderBy('id')
                    ->eachById(function ($order) {
                        if (DB::table('employers')->where('id', $order->attendant_id)->exists()) {
                            return;
                        }

                        $employerId = DB::table('employers')
                            ->where('user_id', $order->attendant_id)
                            ->when(
                                $order->entity_name === 'establishment' && $order->entity_id,
                                fn ($query) => $query->where('establishment_id', $order->entity_id)
                            )
                            ->value('id');

                        DB::table('orders')
                            ->where('id', $order->id)
                            ->update(['attendant_id' => $employerId]);
                    });
            }

            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('attendant_id', 'orders_attendant_id_foreign')
                    ->references('id')
                    ->on('employers')
                    ->nullOnDelete();
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasColumn('orders', 'appointment_status')) {
            DB::statement("ALTER TABLE orders MODIFY appointment_status ENUM('pending','confirmed','rejected','cancelled','completed','attended','no_show') NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'attendant_id')) {
            return;
        }

        $this->dropAttendantForeignKey();

        if (Schema::hasTable('employers') && Schema::hasTable('users')) {
            DB::table('orders')
                ->whereNotNull('attendant_id')
                ->orderBy('id')
                ->eachById(function ($order) {
                    $userId = DB::table('employers')
                        ->where('id', $order->attendant_id)
                        ->value('user_id');

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['attendant_id' => $userId]);
                });

            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('attendant_id', 'orders_attendant_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasColumn('orders', 'appointment_status')) {
            DB::table('orders')->where('appointment_status', 'no_show')->update(['appointment_status' => 'cancelled']);
            DB::statement("ALTER TABLE orders MODIFY appointment_status ENUM('pending','confirmed','rejected','cancelled','completed','attended') NULL");
        }
    }

    private function dropAttendantForeignKey(): void
    {
        $foreignKeys = Schema::getForeignKeys('orders');

        foreach ($foreignKeys as $foreignKey) {
            $columns = $foreignKey['columns'] ?? [];
            if (! in_array('attendant_id', $columns, true)) {
                continue;
            }

            $name = $foreignKey['name'] ?? 'orders_attendant_id_foreign';
            Schema::table('orders', fn (Blueprint $table) => $table->dropForeign($name));
            break;
        }
    }
};
