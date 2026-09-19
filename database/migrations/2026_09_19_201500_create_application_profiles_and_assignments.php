<?php

use App\Services\ApplicationProfileSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('application_profiles')) {
            Schema::create('application_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->string('slug', 120);
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->json('permissions');
                $table->boolean('is_system')->default(true);
                $table->timestamps();

                $table->unique(['application_id', 'slug'], 'application_profiles_app_slug_unique');
                $table->index(['application_id', 'is_system']);
            });
        }

        if (! Schema::hasTable('application_profile_assignments')) {
            Schema::create('application_profile_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('profile_id')->constrained('application_profiles')->cascadeOnDelete();
                $table->string('scope_type', 60)->default('application');
                $table->unsignedBigInteger('scope_id')->default(0);
                $table->string('status', 30)->default('active');
                $table->string('source', 80)->default('system_sync');
                $table->json('metadata')->nullable();
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['application_id', 'user_id', 'profile_id', 'scope_type', 'scope_id'],
                    'app_profile_assignments_unique'
                );
                $table->index(['application_id', 'user_id', 'status'], 'app_profile_assignments_user_status');
                $table->index(['profile_id', 'status'], 'app_profile_assignments_profile_status');
                $table->index(['scope_type', 'scope_id'], 'app_profile_assignments_scope');
            });
        }

        app(ApplicationProfileSyncService::class)->sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('application_profile_assignments');
        Schema::dropIfExists('application_profiles');
    }
};
