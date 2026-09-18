<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_payout_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('app_slug', 80);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->timestamps();
            $table->unique(['app_slug', 'source_type', 'source_id', 'key_hash'], 'fin_payout_idem_scope_unique');
            $table->index(['status', 'updated_at'], 'fin_payout_idem_status_idx');
            $table->index('payout_id', 'fin_payout_idem_payout_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_payout_idempotency_keys');
    }
};
