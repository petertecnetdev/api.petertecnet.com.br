<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_establishment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['application_id', 'establishment_id']);
            $table->index(['establishment_id', 'is_primary']);
        });

        DB::table('establishments')
            ->whereNotNull('app_id')
            ->orderBy('id')
            ->chunkById(500, function ($establishments) {
                $now = now();
                DB::table('application_establishment')->insertOrIgnore(
                    $establishments->map(fn ($establishment) => [
                        'application_id' => $establishment->app_id,
                        'establishment_id' => $establishment->id,
                        'is_primary' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_establishment');
    }
};
