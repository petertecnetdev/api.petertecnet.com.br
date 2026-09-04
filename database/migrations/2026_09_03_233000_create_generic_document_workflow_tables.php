<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id(); $table->foreignId('app_id')->nullable()->constrained('applications')->cascadeOnDelete();
            $table->string('key', 120); $table->string('name', 190); $table->string('document_type', 80)->default('agreement');
            $table->string('status', 30)->default('active'); $table->json('metadata')->nullable(); $table->timestamps(); $table->unique(['app_id', 'key']);
        });
        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('template_id')->constrained('document_templates')->cascadeOnDelete(); $table->unsignedInteger('version');
            $table->longText('content'); $table->json('variables')->nullable(); $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable(); $table->timestamps(); $table->unique(['template_id', 'version']);
        });
        Schema::create('document_clauses', function (Blueprint $table) {
            $table->id(); $table->foreignId('app_id')->nullable()->constrained('applications')->cascadeOnDelete(); $table->string('key', 120); $table->string('name', 190);
            $table->string('category', 80)->default('general'); $table->longText('content'); $table->json('rules')->nullable(); $table->boolean('is_required')->default(false); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['app_id', 'key']);
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique(); $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->string('context_type', 80); $table->string('context_id', 120); $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete(); $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 80)->default('agreement'); $table->string('title', 190); $table->string('status', 40)->default('draft'); $table->unsignedInteger('current_version')->default(0);
            $table->json('payload')->nullable(); $table->timestamp('sent_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamp('cancelled_at')->nullable(); $table->timestamps(); $table->softDeletes();
            $table->index(['app_id', 'context_type', 'context_id']); $table->index(['app_id', 'status']);
        });
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete(); $table->unsignedInteger('version'); $table->string('status', 30)->default('draft');
            $table->longText('content'); $table->json('payload_snapshot')->nullable(); $table->string('content_hash', 64); $table->boolean('is_locked')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('locked_at')->nullable(); $table->timestamps(); $table->unique(['document_id', 'version']);
        });
        Schema::create('document_parties', function (Blueprint $table) {
            $table->id(); $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 60); $table->string('name', 190); $table->string('email', 190)->nullable(); $table->string('tax_id', 40)->nullable();
            $table->unsignedSmallInteger('signing_order')->default(1); $table->boolean('must_sign')->default(true); $table->json('metadata')->nullable(); $table->timestamps(); $table->unique(['document_id', 'role']);
        });
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique(); $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete(); $table->foreignId('document_party_id')->constrained('document_parties')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); $table->string('status', 30)->default('pending'); $table->timestamp('expires_at')->nullable(); $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable(); $table->timestamp('cancelled_at')->nullable(); $table->timestamps(); $table->index(['document_id', 'status']);
        });
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id(); $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete(); $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('document_party_id')->constrained('document_parties')->cascadeOnDelete(); $table->foreignId('signature_request_id')->nullable()->constrained('signature_requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); $table->string('signer_name', 190); $table->string('signer_email', 190)->nullable(); $table->string('signer_tax_id', 40)->nullable();
            $table->string('signature_type', 40)->default('electronic_acknowledgement'); $table->string('signature_hash', 64); $table->string('content_hash', 64); $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable(); $table->json('evidence')->nullable(); $table->timestamp('signed_at'); $table->timestamps(); $table->unique(['document_version_id', 'document_party_id'], 'document_signature_party_version_unique');
        });
        Schema::create('document_audit_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete(); $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete(); $table->string('event_type', 80); $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable(); $table->string('ip_address', 64)->nullable(); $table->text('user_agent')->nullable(); $table->timestamp('occurred_at'); $table->timestamps(); $table->index(['document_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_audit_events'); Schema::dropIfExists('document_signatures'); Schema::dropIfExists('signature_requests'); Schema::dropIfExists('document_parties');
        Schema::dropIfExists('document_versions'); Schema::dropIfExists('documents'); Schema::dropIfExists('document_clauses'); Schema::dropIfExists('document_template_versions'); Schema::dropIfExists('document_templates');
    }
};