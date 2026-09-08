<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('app_notifications', 'dedupe_key')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->string('dedupe_key', 160)->nullable()->after('campaign_id');
                $table->unique(['app_id', 'user_id', 'dedupe_key'], 'app_notifications_dedupe_unique');
            });
        }

        if (! Schema::hasTable('outbound_deliveries')) {
            Schema::create('outbound_deliveries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('app_id')->index();
                $table->string('channel', 40);
                $table->string('dedupe_key', 160);
                $table->string('status', 24)->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('locked_at')->nullable()->index();
                $table->timestamp('delivered_at')->nullable()->index();
                $table->timestamp('failed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['app_id', 'channel', 'dedupe_key'], 'outbound_deliveries_dedupe_unique');
                $table->index(['status', 'locked_at'], 'outbound_deliveries_retry_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_deliveries');

        if (Schema::hasColumn('app_notifications', 'dedupe_key')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->dropUnique('app_notifications_dedupe_unique');
                $table->dropColumn('dedupe_key');
            });
        }
    }
};
