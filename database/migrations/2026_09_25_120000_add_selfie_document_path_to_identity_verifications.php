<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('identity_verifications', 'selfie_document_path')) {
                $table->string('selfie_document_path', 2048)->nullable()->after('document_back_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('identity_verifications', function (Blueprint $table) {
            if (Schema::hasColumn('identity_verifications', 'selfie_document_path')) {
                $table->dropColumn('selfie_document_path');
            }
        });
    }
};
