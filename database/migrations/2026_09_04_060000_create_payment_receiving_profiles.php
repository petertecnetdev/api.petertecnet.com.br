<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receiving_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 20)->default('pix');
            $table->string('pix_key_type', 20);
            $table->text('pix_key');
            $table->char('pix_key_hash', 64);
            $table->string('pix_key_masked', 190);
            $table->string('holder_name', 190);
            $table->string('merchant_city', 80);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'user_id', 'method'], 'payment_receiving_profiles_unique');
            $table->index(['app_id', 'method', 'is_active']);
            $table->index('pix_key_hash');
        });

        Schema::table('lease_charges', function (Blueprint $table) {
            $table->foreignId('payment_receiving_profile_id')
                ->nullable()
                ->after('ecosystem_payment_id')
                ->constrained('payment_receiving_profiles')
                ->nullOnDelete();
            $table->string('pix_txid', 25)->nullable()->after('provider_payment_id')->index();
            $table->longText('pix_payload')->nullable()->after('pix_txid');
            $table->json('payment_recipient_snapshot')->nullable()->after('pix_payload');
        });
    }

    public function down(): void
    {
        Schema::table('lease_charges', function (Blueprint $table) {
            $table->dropForeign(['payment_receiving_profile_id']);
            $table->dropColumn([
                'payment_receiving_profile_id',
                'pix_txid',
                'pix_payload',
                'payment_recipient_snapshot',
            ]);
        });

        Schema::dropIfExists('payment_receiving_profiles');
    }
};
