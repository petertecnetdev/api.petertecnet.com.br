<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payflow_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('document', 40)->nullable();
            $table->string('source', 50)->nullable();
            $table->string('status', 30)->default('lead');
            $table->text('notes')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamps();
            $table->index(['app_id', 'establishment_id', 'status']);
            $table->index(['owner_user_id', 'app_id']);
            $table->unique(['establishment_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payflow_contacts');
    }
};
