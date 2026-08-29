<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ecosystem_settings')) {
            Schema::create('ecosystem_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group', 100)->default('site')->index();
                $table->string('key', 150);
                $table->json('value')->nullable();
                $table->boolean('is_public')->default(false)->index();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['group', 'key']);
            });
        }

        if (! Schema::hasTable('ecosystem_audit_logs')) {
            Schema::create('ecosystem_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 150)->index();
                $table->string('entity_type', 255)->nullable()->index();
                $table->unsignedBigInteger('entity_id')->nullable()->index();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('ip', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index(['entity_type', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosystem_audit_logs');
        Schema::dropIfExists('ecosystem_settings');
    }
};
