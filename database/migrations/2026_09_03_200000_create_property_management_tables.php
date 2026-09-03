<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('managed_properties', function (Blueprint $table) {
            $table->id();
            $table->string('application_slug', 80)->index();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('property_type', 50)->default('house');
            $table->string('usage_type', 30)->default('residential');
            $table->string('address');
            $table->string('address_number', 40)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120);
            $table->string('state', 2);
            $table->string('postal_code', 16)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['application_slug', 'owner_user_id']);
        });

        Schema::create('lease_agreements', function (Blueprint $table) {
            $table->id();
            $table->string('application_slug', 80)->index();
            $table->foreignId('property_id')->constrained('managed_properties')->cascadeOnDelete();
            $table->foreignId('landlord_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenant_name', 180);
            $table->string('tenant_email', 180)->index();
            $table->string('tenant_cpf', 20)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('rent_amount', 14, 2);
            $table->unsignedTinyInteger('due_day')->default(10);
            $table->string('guarantee_type', 40)->default('none');
            $table->unsignedTinyInteger('security_rent_multiplier')->default(0);
            $table->decimal('security_amount', 14, 2)->default(0);
            $table->json('included_charges')->nullable();
            $table->json('clauses')->nullable();
            $table->json('terms')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['application_slug', 'landlord_user_id']);
        });

        Schema::create('lease_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('lease_agreements')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('responsible_party', 30)->default('tenant');
            $table->boolean('included_in_rent')->default(false);
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('billing_rule')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('agreement_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('lease_agreements')->cascadeOnDelete();
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_role', 30);
            $table->string('signer_name', 180);
            $table->string('signer_email', 180);
            $table->string('method', 40)->default('electronic_acceptance');
            $table->string('evidence_hash', 64)->unique();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['agreement_id', 'signer_role']);
        });

        Schema::create('property_documents', function (Blueprint $table) {
            $table->id();
            $table->string('application_slug', 80)->index();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('lease_agreements')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('managed_properties')->cascadeOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 60);
            $table->string('name', 220);
            $table->string('storage_path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 30)->default('received');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['agreement_id', 'category']);
        });

        Schema::create('lease_receivables', function (Blueprint $table) {
            $table->id();
            $table->string('application_slug', 80)->index();
            $table->foreignId('agreement_id')->constrained('lease_agreements')->cascadeOnDelete();
            $table->string('type', 50)->default('rent');
            $table->string('description', 180);
            $table->date('period_start');
            $table->date('due_date')->index();
            $table->decimal('amount', 14, 2);
            $table->string('status', 30)->default('pending')->index();
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_provider', 80)->nullable();
            $table->string('external_reference', 180)->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['agreement_id', 'type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_receivables');
        Schema::dropIfExists('property_documents');
        Schema::dropIfExists('agreement_signatures');
        Schema::dropIfExists('lease_obligations');
        Schema::dropIfExists('lease_agreements');
        Schema::dropIfExists('managed_properties');
    }
};
