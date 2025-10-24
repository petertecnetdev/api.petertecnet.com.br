<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employer_id');
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'
            ]);
            $table->date('reserved_date')->nullable(); // data específica (feriado ou pausa)
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->enum('type', ['work', 'break', 'holiday'])->default('work'); // tipo do horário
            $table->timestamps();

            $table->foreign('employer_id')
                ->references('id')
                ->on('employers')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_schedules');
    }
};
