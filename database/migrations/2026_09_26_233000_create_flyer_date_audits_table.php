<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flyer_date_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('image_fingerprint', 64);
            $table->dateTimeTz('expected_start_at')->nullable();
            $table->string('expected_start_key', 40)->default('none');
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->boolean('recurring')->default(false);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->string('status', 30)->default('queued')->index();
            $table->json('result')->nullable();
            $table->string('review_action', 30)->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['app_id', 'entity_type', 'entity_id', 'image_fingerprint', 'expected_start_key'], 'flyer_date_audit_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyer_date_audits');
    }
};
