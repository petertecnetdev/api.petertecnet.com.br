<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('organization_media_reports')) {
            return;
        }

        Schema::create('organization_media_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('reporter_user_id');
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 24)->default('open');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status', 'created_at'], 'org_media_reports_queue_idx');
            $table->index(['app_id', 'organization_id', 'media_id'], 'org_media_reports_media_idx');
            $table->index(['reporter_user_id', 'created_at'], 'org_media_reports_reporter_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_media_reports');
    }
};
