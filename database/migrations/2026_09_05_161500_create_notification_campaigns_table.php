<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->unsignedBigInteger('app_id')->nullable()->index();
            $table->string('audience_type', 30)->index();
            $table->json('recipient_user_ids')->nullable();
            $table->string('type', 80)->default('general')->index();
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->string('reference_url', 500)->nullable();
            $table->json('data')->nullable();
            $table->string('status', 30)->default('sending')->index();
            $table->unsignedBigInteger('recipients_count')->default(0);
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['app_id', 'status', 'created_at'], 'notification_campaigns_app_status_idx');
        });

        if (! Schema::hasColumn('app_notifications', 'campaign_id')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_notifications') && Schema::hasColumn('app_notifications', 'campaign_id')) {
            Schema::table('app_notifications', function (Blueprint $table) {
                $table->dropIndex(['campaign_id']);
                $table->dropColumn('campaign_id');
            });
        }

        Schema::dropIfExists('notification_campaigns');
    }
};
