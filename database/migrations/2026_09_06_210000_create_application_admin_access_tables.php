<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('application_admin_profiles')) {
            Schema::create('application_admin_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('slug', 120);
                $table->text('description')->nullable();
                $table->json('permissions');
                $table->boolean('is_system')->default(false);
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['application_id', 'slug'], 'application_admin_profiles_app_slug_unique');
                $table->index(['application_id', 'is_system']);
            });
        }

        if (! Schema::hasTable('application_admin_assignments')) {
            Schema::create('application_admin_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('profile_id')->constrained('application_admin_profiles')->cascadeOnDelete();
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('active');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['application_id', 'user_id'], 'application_admin_assignments_app_user_unique');
                $table->index(['application_id', 'status']);
                $table->index(['profile_id', 'status']);
            });
        }

        if (! Schema::hasTable('application_admin_audits')) {
            Schema::create('application_admin_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 120);
                $table->json('metadata')->nullable();
                $table->string('request_id', 120)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index(['application_id', 'created_at']);
                $table->index(['actor_user_id', 'created_at']);
                $table->index(['target_user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('application_admin_audits');
        Schema::dropIfExists('application_admin_assignments');
        Schema::dropIfExists('application_admin_profiles');
    }
};
