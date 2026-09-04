<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 160)->unique();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('status', 30)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'establishment_id']);
            $table->index(['establishment_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->cascadeOnDelete();
            $table->string('resource_type', 120)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('context_key', 191);
            $table->string('status', 30)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'context_key'], 'role_assignments_user_role_context_unique');
            $table->index(['user_id', 'application_id', 'status'], 'role_assignments_user_app_status_idx');
            $table->index(['establishment_id', 'status'], 'role_assignments_establishment_status_idx');
            $table->index(['resource_type', 'resource_id'], 'role_assignments_resource_idx');
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->default('person');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('legal_name', 200);
            $table->string('display_name', 200)->nullable();
            $table->string('document_type', 30)->nullable();
            $table->string('document', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document']);
            $table->index('email');
        });

        Schema::create('resource_relationships', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('relationship_type', 100);
            $table->string('resource_type', 120);
            $table->unsignedBigInteger('resource_id');
            $table->string('relationship_key', 191)->unique();
            $table->string('status', 30)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'status'], 'resource_relationships_subject_idx');
            $table->index(['resource_type', 'resource_id', 'status'], 'resource_relationships_resource_idx');
            $table->index(['application_id', 'relationship_type', 'status'], 'resource_relationships_app_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_relationships');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
