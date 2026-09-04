<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('lease_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 64)->index();
            $table->json('snapshot');
            $table->json('changes')->nullable();
            $table->string('snapshot_sha256', 64)->index();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['app_id', 'lease_id', 'sequence']);
        });

        Schema::create('lease_amendments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('lease_id')->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->string('type', 48)->default('other')->index();
            $table->string('status', 32)->default('draft')->index();
            $table->date('effective_on');
            $table->string('summary', 255);
            $table->json('changes');
            $table->longText('document_text')->nullable();
            $table->string('document_sha256', 64)->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'lease_id', 'status']);
        });

        Schema::create('lease_amendment_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('amendment_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('party', 24);
            $table->string('signer_name', 190);
            $table->string('signer_email', 190)->nullable();
            $table->string('signature_hash', 64);
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
            $table->unique(['amendment_id', 'party']);
        });

        Schema::create('property_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('lease_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->index();
            $table->string('category', 48)->default('other')->index();
            $table->string('name', 190);
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable()->index();
            $table->boolean('sensitive')->default(false)->index();
            $table->date('retention_until')->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['app_id', 'property_id', 'category']);
        });

        Schema::create('leasing_access_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->unsignedBigInteger('lease_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('granted_by_user_id')->nullable()->index();
            $table->string('role', 40)->index();
            $table->json('permissions')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['app_id', 'user_id', 'role']);
        });

        Schema::create('property_availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('lease_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->string('type', 40)->index();
            $table->string('status', 24)->default('planned')->index();
            $table->date('starts_on')->index();
            $table->date('ends_on')->index();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'property_id', 'starts_on', 'ends_on']);
        });

        Schema::create('leasing_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->unique();
            $table->json('settings');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('property_inspections', function (Blueprint $table) {
            $table->string('status', 24)->default('draft')->index();
            $table->json('checklist')->nullable();
            $table->json('meter_readings')->nullable();
            $table->unsignedBigInteger('comparison_inspection_id')->nullable()->index();
            $table->timestamp('landlord_signed_at')->nullable();
            $table->timestamp('tenant_signed_at')->nullable();
            $table->string('evidence_sha256', 64)->nullable()->index();
            $table->timestamp('finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('property_inspections', function (Blueprint $table) {
            $table->dropColumn(['status', 'checklist', 'meter_readings', 'comparison_inspection_id', 'landlord_signed_at', 'tenant_signed_at', 'evidence_sha256', 'finalized_at']);
        });
        Schema::dropIfExists('leasing_policies');
        Schema::dropIfExists('property_availability_blocks');
        Schema::dropIfExists('leasing_access_grants');
        Schema::dropIfExists('property_documents');
        Schema::dropIfExists('lease_amendment_signatures');
        Schema::dropIfExists('lease_amendments');
        Schema::dropIfExists('lease_revisions');
    }
};
