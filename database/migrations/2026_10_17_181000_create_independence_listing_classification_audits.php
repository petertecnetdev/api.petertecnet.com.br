<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('independence_listing_classification_audits')) {
            Schema::create('independence_listing_classification_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('app_id');
                $table->string('entity_type', 24);
                $table->unsignedBigInteger('entity_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->unsignedBigInteger('institutional_author_id');
                $table->string('source_url', 2048);
                $table->text('review_note');
                $table->timestamp('created_at');
                $table->index(['app_id', 'entity_type', 'entity_id'], 'independence_classification_history');
            });
        }
    }

    public function down(): void
    {
        // Preserve provenance records during rollback.
    }
};
