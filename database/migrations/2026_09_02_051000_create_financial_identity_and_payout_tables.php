<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('legal_name', 190);
            $table->string('document_type', 10)->default('CPF');
            $table->text('document_number');
            $table->char('document_number_hash', 64)->unique();
            $table->date('birthdate')->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('verification_level', 40)->default('none');
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'verification_level']);
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('financial_beneficiaries')->cascadeOnDelete();
            $table->string('provider', 50)->default('aws_rekognition');
            $table->string('status', 40)->default('pending');
            $table->string('document_type', 30)->nullable();
            $table->text('document_front_path')->nullable();
            $table->text('document_back_path')->nullable();
            $table->string('document_status', 40)->default('pending');
            $table->string('liveness_session_id', 190)->nullable()->index();
            $table->string('liveness_status', 40)->default('pending');
            $table->string('face_match_status', 40)->default('pending');
            $table->decimal('face_similarity', 6, 3)->nullable();
            $table->decimal('liveness_confidence', 6, 3)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['beneficiary_id', 'status']);
        });

        Schema::create('financial_payout_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('financial_beneficiaries')->cascadeOnDelete();
            $table->string('source_type', 50)->default('production');
            $table->unsignedBigInteger('source_id');
            $table->string('provider', 50)->default('asaas');
            $table->string('type', 20)->default('pix');
            $table->string('pix_key_type', 20);
            $table->text('pix_key');
            $table->char('pix_key_hash', 64);
            $table->string('pix_key_masked', 190);
            $table->string('holder_name', 190)->nullable();
            $table->string('holder_document_masked', 40)->nullable();
            $table->string('status', 40)->default('pending');
            $table->json('provider_snapshot')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('cooling_until')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['beneficiary_id', 'status']);
            $table->index('pix_key_hash');
        });

        Schema::create('financial_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('app_slug', 80)->nullable();
            $table->string('source_type', 50)->default('production');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('beneficiary_id')->constrained('financial_beneficiaries')->restrictOnDelete();
            $table->foreignId('payout_destination_id')->constrained('financial_payout_destinations')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->string('provider', 50)->default('asaas');
            $table->string('status', 40)->default('pending');
            $table->decimal('amount', 14, 2);
            $table->string('provider_transfer_id', 190)->nullable()->index();
            $table->uuid('idempotency_key')->unique();
            $table->string('risk_status', 40)->default('approved');
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'status']);
        });

        Schema::create('financial_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('event_id', 190);
            $table->string('event_type', 100)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_webhook_events');
        Schema::dropIfExists('financial_payouts');
        Schema::dropIfExists('financial_payout_destinations');
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('financial_beneficiaries');
    }
};
