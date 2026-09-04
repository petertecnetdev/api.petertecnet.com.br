<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 160);
            $table->string('category', 80)->default('business');
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['category', 'is_active']);
        });

        Schema::create('resource_refs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained('establishments')->nullOnDelete();
            $table->string('resource_type', 120);
            $table->unsignedBigInteger('resource_id');
            $table->string('label', 220)->nullable();
            $table->string('status', 30)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'resource_type', 'resource_id'], 'resource_refs_app_type_id_unique');
            $table->index(['application_id', 'resource_type', 'status'], 'resource_refs_app_type_status_idx');
            $table->index(['establishment_id', 'status'], 'resource_refs_establishment_status_idx');
        });

        Schema::create('party_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relationship_type', 80)->default('representative');
            $table->string('status', 30)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['party_id', 'user_id', 'relationship_type'], 'party_users_party_user_relationship_unique');
            $table->index(['user_id', 'status'], 'party_users_user_status_idx');
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->text('document_encrypted')->nullable()->after('document');
            $table->char('document_hash', 64)->nullable()->after('document_encrypted');
            $table->index(['document_type', 'document_hash'], 'parties_document_hash_idx');
        });

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->foreignId('resource_ref_id')->nullable()->after('establishment_id')->constrained('resource_refs')->nullOnDelete();
            $table->index(['resource_ref_id', 'status'], 'role_assignments_resource_ref_status_idx');
        });

        Schema::table('resource_relationships', function (Blueprint $table) {
            $table->foreignId('resource_ref_id')->nullable()->after('application_id')->constrained('resource_refs')->nullOnDelete();
            $table->index(['resource_ref_id', 'status'], 'resource_relationships_resource_ref_status_idx');
        });

        Schema::table('ecosystem_audit_logs', function (Blueprint $table) {
            $table->string('request_id', 100)->nullable()->after('user_agent');
            $table->foreignId('application_id')->nullable()->after('request_id')->constrained('applications')->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->after('application_id')->constrained('establishments')->nullOnDelete();
            $table->json('metadata')->nullable()->after('establishment_id');
            $table->index(['application_id', 'created_at'], 'ecosystem_audit_logs_app_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ecosystem_audit_logs', function (Blueprint $table) {
            $table->dropIndex('ecosystem_audit_logs_app_created_idx');
            $table->dropConstrainedForeignId('establishment_id');
            $table->dropConstrainedForeignId('application_id');
            $table->dropColumn(['request_id', 'metadata']);
        });

        Schema::table('resource_relationships', function (Blueprint $table) {
            $table->dropIndex('resource_relationships_resource_ref_status_idx');
            $table->dropConstrainedForeignId('resource_ref_id');
        });

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropIndex('role_assignments_resource_ref_status_idx');
            $table->dropConstrainedForeignId('resource_ref_id');
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex('parties_document_hash_idx');
            $table->dropColumn(['document_encrypted', 'document_hash']);
        });

        Schema::dropIfExists('party_users');
        Schema::dropIfExists('resource_refs');
        Schema::dropIfExists('relationship_types');
    }
};