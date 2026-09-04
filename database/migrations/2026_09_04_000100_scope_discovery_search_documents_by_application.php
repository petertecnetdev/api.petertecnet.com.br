<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('discovery_search_documents', function (Blueprint $table) {
            $table->dropUnique('discovery_search_documents_document_type_document_id_unique');
            $table->unique(
                ['application_id', 'document_type', 'document_id'],
                'discovery_search_documents_app_type_document_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('discovery_search_documents', function (Blueprint $table) {
            $table->dropUnique('discovery_search_documents_app_type_document_unique');
            $table->unique(
                ['document_type', 'document_id'],
                'discovery_search_documents_document_type_document_id_unique'
            );
        });
    }
};
