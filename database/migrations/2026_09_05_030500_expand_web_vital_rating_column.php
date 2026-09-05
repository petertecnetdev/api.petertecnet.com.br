<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_vital_samples', function (Blueprint $table) {
            $table->string('rating', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the wider column on rollback. Existing Web Vitals may contain
        // "needs-improvement", which does not fit the historical 16-char field.
    }
};
