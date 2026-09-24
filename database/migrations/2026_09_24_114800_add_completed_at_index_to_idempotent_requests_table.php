<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->index(['completed_at', 'id'], 'idempotent_requests_completed_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotent_requests', function (Blueprint $table) {
            $table->dropIndex('idempotent_requests_completed_at_id_index');
        });
    }
};
