<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_producer_profiles')) {
            Schema::create('event_producer_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('application_id')->nullable()->index();
                $table->unsignedBigInteger('establishment_id')->unique();
                $table->integer('capacity')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('ticket_price_min', 10, 2)->nullable();
                $table->decimal('ticket_price_max', 10, 2)->nullable();
                $table->integer('total_tickets_sold')->nullable();
                $table->integer('total_tickets_available')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->text('other_information')->nullable();
                $table->longText('images')->nullable();
                $table->timestamps();

                $table->foreign('application_id')->references('id')->on('applications')->nullOnDelete();
                $table->foreign('establishment_id')->references('id')->on('establishments')->cascadeOnDelete();
                $table->index(['application_id', 'establishment_id']);
            });
        }

        $columns = [
            'capacity', 'start_date', 'end_date', 'ticket_price_min', 'ticket_price_max',
            'total_tickets_sold', 'total_tickets_available', 'contact_email', 'contact_phone',
            'other_information', 'images',
        ];

        $query = DB::table('establishments')->where('category', 'production')->orderBy('id');
        foreach ($query->get() as $establishment) {
            $payload = [
                'application_id' => $establishment->app_id,
                'establishment_id' => $establishment->id,
                'created_at' => $establishment->created_at ?: now(),
                'updated_at' => $establishment->updated_at ?: now(),
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('establishments', $column)) {
                    $payload[$column] = $establishment->{$column};
                }
            }

            DB::table('event_producer_profiles')->updateOrInsert(
                ['establishment_id' => $establishment->id],
                $payload,
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_producer_profiles');
    }
};
