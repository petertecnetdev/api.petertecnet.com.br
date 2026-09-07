<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_user_annotations')) {
            return;
        }

        Schema::create('admin_user_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 30)->index();
            $table->text('value');
            $table->boolean('is_pinned')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['target_user_id', 'kind', 'created_at'], 'aua_target_kind_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_user_annotations');
    }
};
