<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cutinapp_producer_payment_accounts', function (Blueprint $table) {
            $table->text('access_token')->nullable()->after('provider_recipient_id');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('cutinapp_producer_payment_accounts', function (Blueprint $table) {
            $table->dropColumn(['access_token', 'refresh_token', 'token_expires_at']);
        });
    }
};
