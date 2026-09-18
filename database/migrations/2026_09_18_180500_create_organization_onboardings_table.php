<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_onboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('establishment_id')->index();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->unsignedBigInteger('assisted_by_user_id')->nullable()->index();
            $table->unsignedBigInteger('initial_event_id')->nullable()->index();
            $table->string('status', 40)->default('assisted_setup');
            $table->string('authorized_email')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('handoff_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'establishment_id'], 'organization_onboardings_app_establishment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_onboardings');
    }
};
