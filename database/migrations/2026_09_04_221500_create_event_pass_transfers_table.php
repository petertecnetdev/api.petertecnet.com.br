<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_pass_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_pass_id')->constrained('event_passes')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_holder_name')->nullable();
            $table->string('from_holder_email')->nullable();
            $table->string('to_holder_name')->nullable();
            $table->string('to_holder_email')->nullable();
            $table->char('previous_token_hash', 64);
            $table->char('new_token_hash', 64);
            $table->timestamp('transferred_at')->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['event_pass_id', 'transferred_at'], 'event_pass_transfers_pass_time_idx');
            $table->index(['from_user_id', 'transferred_at'], 'event_pass_transfers_from_time_idx');
            $table->index(['to_user_id', 'transferred_at'], 'event_pass_transfers_to_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_pass_transfers');
    }
};
