<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->json('branding')->nullable()->after('logo');
            $table->json('branding_draft')->nullable()->after('branding');
            $table->unsignedBigInteger('branding_version')->default(0)->after('branding_draft');
            $table->timestamp('branding_updated_at')->nullable()->after('branding_version');
            $table->timestamp('branding_published_at')->nullable()->after('branding_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'branding',
                'branding_draft',
                'branding_version',
                'branding_updated_at',
                'branding_published_at',
            ]);
        });
    }
};
