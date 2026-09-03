<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduling_resources')) {
            Schema::create('scheduling_resources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->unsignedBigInteger('establishment_id');
                $table->unsignedBigInteger('employer_id')->nullable();
                $table->string('type', 40)->default('professional');
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('capacity')->default(1);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('app_id')->references('id')->on('applications')->cascadeOnDelete();
                $table->foreign('establishment_id')->references('id')->on('establishments')->cascadeOnDelete();
                $table->foreign('employer_id')->references('id')->on('employers')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

                $table->unique(['establishment_id', 'employer_id'], 'scheduling_resources_establishment_employer_unique');
                $table->index(['app_id', 'establishment_id', 'is_active'], 'scheduling_resources_scope_index');
                $table->index(['establishment_id', 'type'], 'scheduling_resources_type_index');
            });
        }

        if (! Schema::hasTable('scheduling_resource_schedules')) {
            Schema::create('scheduling_resource_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('scheduling_resource_id');
                $table->string('day_of_week', 16)->nullable();
                $table->date('reserved_date')->nullable();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->string('type', 24)->default('work');
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('scheduling_resource_id')
                    ->references('id')
                    ->on('scheduling_resources')
                    ->cascadeOnDelete();

                $table->index(
                    ['scheduling_resource_id', 'day_of_week', 'type', 'is_active'],
                    'scheduling_resource_schedules_week_index'
                );
                $table->index(
                    ['scheduling_resource_id', 'reserved_date', 'type'],
                    'scheduling_resource_schedules_date_index'
                );
            });
        }

        if (! Schema::hasTable('scheduling_resource_item')) {
            Schema::create('scheduling_resource_item', function (Blueprint $table) {
                $table->unsignedBigInteger('scheduling_resource_id');
                $table->unsignedBigInteger('item_id');
                $table->timestamps();

                $table->foreign('scheduling_resource_id')
                    ->references('id')
                    ->on('scheduling_resources')
                    ->cascadeOnDelete();
                $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
                $table->primary(['scheduling_resource_id', 'item_id'], 'scheduling_resource_item_primary');
            });
        }

        if (! Schema::hasTable('order_scheduling_resource')) {
            Schema::create('order_scheduling_resource', function (Blueprint $table) {
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('scheduling_resource_id');
                $table->string('role', 40)->default('required');
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('scheduling_resource_id')
                    ->references('id')
                    ->on('scheduling_resources')
                    ->cascadeOnDelete();
                $table->primary(['order_id', 'scheduling_resource_id'], 'order_scheduling_resource_primary');
            });
        }

        $this->bootstrapProfessionalResources();
    }

    private function bootstrapProfessionalResources(): void
    {
        if (! Schema::hasTable('employers') || ! Schema::hasTable('users') || ! Schema::hasTable('establishments')) {
            return;
        }

        DB::table('employers')
            ->join('establishments', 'establishments.id', '=', 'employers.establishment_id')
            ->join('users', 'users.id', '=', 'employers.user_id')
            ->select([
                'employers.id as employer_id',
                'employers.establishment_id',
                'employers.created_by',
                'employers.updated_by',
                'establishments.app_id',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.email',
            ])
            ->orderBy('employers.id')
            ->chunk(250, function ($rows) {
                $now = now();

                foreach ($rows as $row) {
                    if (! $row->app_id) {
                        continue;
                    }

                    $name = trim((string) $row->first_name . ' ' . (string) $row->last_name);
                    $name = $name !== '' ? $name : ((string) ($row->user_name ?: $row->email ?: 'Profissional'));

                    DB::table('scheduling_resources')->updateOrInsert(
                        [
                            'establishment_id' => (int) $row->establishment_id,
                            'employer_id' => (int) $row->employer_id,
                        ],
                        [
                            'app_id' => (int) $row->app_id,
                            'type' => 'professional',
                            'name' => $name,
                            'capacity' => 1,
                            'is_active' => true,
                            'created_by' => $row->created_by,
                            'updated_by' => $row->updated_by,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_scheduling_resource');
        Schema::dropIfExists('scheduling_resource_item');
        Schema::dropIfExists('scheduling_resource_schedules');
        Schema::dropIfExists('scheduling_resources');
    }
};
