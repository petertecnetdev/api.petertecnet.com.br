<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_campaigns')) {
            return;
        }

        Schema::table('notification_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('notification_campaigns', 'channels')) {
                $table->json('channels')->nullable()->after('data');
            }
            if (! Schema::hasColumn('notification_campaigns', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('status')->index();
            }
            if (! Schema::hasColumn('notification_campaigns', 'delivered_count')) {
                $table->unsignedBigInteger('delivered_count')->default(0)->after('recipients_count');
            }
            if (! Schema::hasColumn('notification_campaigns', 'failed_count')) {
                $table->unsignedBigInteger('failed_count')->default(0)->after('delivered_count');
            }
            if (! Schema::hasColumn('notification_campaigns', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('sent_at')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_campaigns')) {
            return;
        }

        Schema::table('notification_campaigns', function (Blueprint $table) {
            foreach (['channels', 'scheduled_at', 'delivered_count', 'failed_count', 'completed_at'] as $column) {
                if (Schema::hasColumn('notification_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
