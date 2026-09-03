<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('type', 40)->default('house');
            $table->string('use_type', 30)->default('residential');
            $table->string('status', 30)->default('available');
            $table->string('postal_code', 12)->nullable();
            $table->string('street', 190);
            $table->string('number', 40)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('neighborhood', 120)->nullable();
            $table->string('city', 120);
            $table->string('state', 2);
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->decimal('area_m2', 10, 2)->nullable();
            $table->decimal('default_rent_amount', 12, 2)->nullable();
            $table->unsignedTinyInteger('default_due_day')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'owner_user_id', 'status']);
            $table->index(['app_id', 'city', 'state']);
        });

        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('landlord_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenant_name', 190);
            $table->string('tenant_email', 190)->nullable();
            $table->string('tenant_phone', 40)->nullable();
            $table->string('tenant_tax_id', 32)->nullable();
            $table->string('purpose', 30)->default('residential');
            $table->string('status', 40)->default('draft');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('rent_amount', 12, 2);
            $table->unsignedTinyInteger('due_day')->default(10);
            $table->unsignedTinyInteger('deposit_months')->default(0);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->string('guarantee_type', 40)->default('none');
            $table->string('adjustment_index', 40)->nullable();
            $table->unsignedSmallInteger('adjustment_frequency_months')->default(12);
            $table->json('clauses')->nullable();
            $table->json('included_expenses')->nullable();
            $table->json('tenant_expenses')->nullable();
            $table->unsignedInteger('contract_version')->default(1);
            $table->longText('contract_text')->nullable();
            $table->timestamp('contract_generated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_id', 'landlord_user_id', 'status']);
            $table->index(['app_id', 'tenant_user_id', 'status']);
            $table->index(['app_id', 'property_id', 'starts_on']);
        });

        Schema::create('lease_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40)->default('other');
            $table->string('name', 190);
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'lease_id', 'category']);
        });

        Schema::create('lease_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('party', 30);
            $table->string('signer_name', 190);
            $table->string('signer_email', 190)->nullable();
            $table->string('signer_tax_id', 32)->nullable();
            $table->string('signature_type', 30)->default('electronic_acknowledgement');
            $table->string('signature_hash', 64);
            $table->string('provider', 50)->nullable();
            $table->string('provider_envelope_id', 190)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'party'], 'lease_signatures_party_unique');
            $table->index(['app_id', 'user_id']);
        });

        Schema::create('lease_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
            $table->foreignId('ecosystem_payment_id')->nullable()->constrained('ecosystem_payments')->nullOnDelete();
            $table->string('type', 40)->default('rent');
            $table->string('description', 190);
            $table->date('reference_date')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->string('payment_method', 30)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('provider_payment_id', 190)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'lease_id', 'status', 'due_date']);
            $table->index(['app_id', 'status', 'due_date']);
        });

        Schema::create('property_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->default('entry');
            $table->timestamp('occurred_at');
            $table->text('summary')->nullable();
            $table->json('items')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'property_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_inspections');
        Schema::dropIfExists('lease_charges');
        Schema::dropIfExists('lease_signatures');
        Schema::dropIfExists('lease_documents');
        Schema::dropIfExists('leases');
        Schema::dropIfExists('properties');
    }
};