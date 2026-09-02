<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('people_profiles')) {
            Schema::create('people_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('kind', 40)->default('person')->index();
                $table->string('display_name', 180);
                $table->string('slug', 190)->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['application_id', 'user_id']);
                $table->index(['application_id', 'organization_id']);
                $table->unique(['application_id', 'slug']);
            });
        }

        if (! Schema::hasTable('teams')) {
            Schema::create('teams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 180);
                $table->string('slug', 190);
                $table->string('type', 40)->default('team')->index();
                $table->string('status', 30)->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['application_id', 'slug']);
            });
        }

        if (! Schema::hasTable('team_memberships')) {
            Schema::create('team_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
                $table->foreignId('person_profile_id')->constrained('people_profiles')->cascadeOnDelete();
                $table->string('role', 60)->default('member');
                $table->string('status', 30)->default('active')->index();
                $table->json('metadata')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['team_id', 'person_profile_id']);
            });
        }

        if (! Schema::hasTable('admission_types')) {
            Schema::create('admission_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->string('name', 180);
                $table->string('status', 30)->default('active')->index();
                $table->decimal('price', 12, 2)->default(0);
                $table->unsignedInteger('capacity')->nullable();
                $table->timestamp('sales_start_at')->nullable();
                $table->timestamp('sales_end_at')->nullable();
                $table->string('legacy_source_type', 80)->nullable();
                $table->unsignedBigInteger('legacy_source_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'event_id', 'status']);
                $table->unique(['application_id', 'legacy_source_type', 'legacy_source_id'], 'admission_legacy_source_unique');
            });
        }

        if (! Schema::hasTable('admission_credentials')) {
            Schema::create('admission_credentials', function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('admission_type_id')->constrained('admission_types')->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('valid')->index();
                $table->string('code_hash', 255)->nullable()->index();
                $table->string('source_type', 80)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->timestamp('checked_in_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['application_id', 'user_id', 'status']);
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('check_ins')) {
            Schema::create('check_ins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
                $table->foreignId('admission_credential_id')->constrained('admission_credentials')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('result', 30)->default('accepted')->index();
                $table->string('device_id', 190)->nullable();
                $table->string('request_id', 64)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('checked_in_at')->index();
                $table->timestamps();
                $table->index(['application_id', 'admission_credential_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('admission_credentials');
        Schema::dropIfExists('admission_types');
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('people_profiles');
    }
};
