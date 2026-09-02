<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutinapp_producer_contract_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_id')->constrained('productions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('contract_version', 40);
            $table->string('contract_hash', 64);
            $table->longText('contract_snapshot');
            $table->string('signer_name', 255);
            $table->string('signer_document', 32);
            $table->string('signer_role', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['production_id', 'contract_version'], 'cutinapp_contract_production_version_unique');
            $table->index(['user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutinapp_producer_contract_acceptances');
    }
};
