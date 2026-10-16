<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('curation_provenance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id');
            $table->char('source_hash', 64);
            $table->text('source_url');
            $table->string('source_platform', 64)->nullable();
            $table->date('verified_on')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'entity_type', 'entity_id', 'source_hash'], 'curation_source_unique');
            $table->index(['app_id', 'entity_type', 'entity_id'], 'curation_entity_lookup');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('curation_provenance');
    }
};
