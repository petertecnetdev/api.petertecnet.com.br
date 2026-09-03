<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('lifecycle_status', 32)->default('scheduled')->index()->after('is_cancelled');
            $table->timestamp('sales_paused_at')->nullable()->after('lifecycle_status');
            $table->timestamp('cancelled_at')->nullable()->after('sales_paused_at');
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('cancelled_at');
            $table->timestamp('postponed_at')->nullable()->after('cancelled_by_user_id');
            $table->unsignedBigInteger('postponed_by_user_id')->nullable()->after('postponed_at');
            $table->timestamp('rescheduled_at')->nullable()->after('postponed_by_user_id');
            $table->unsignedBigInteger('rescheduled_by_user_id')->nullable()->after('rescheduled_at');
            $table->timestamp('previous_start_date')->nullable()->after('rescheduled_by_user_id');
            $table->timestamp('previous_end_date')->nullable()->after('previous_start_date');
            $table->timestamp('refund_deadline_at')->nullable()->after('previous_end_date');
            $table->text('lifecycle_reason')->nullable()->after('refund_deadline_at');
        });

        Schema::create('event_lifecycle_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('action', 40)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->timestamp('previous_start_date')->nullable();
            $table->timestamp('previous_end_date')->nullable();
            $table->timestamp('new_start_date')->nullable();
            $table->timestamp('new_end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'event_id', 'created_at'], 'event_lifecycle_app_event_created_idx');
        });

        Schema::create('commerce_refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->string('source_type', 40)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('BRL');
            $table->string('provider', 40)->nullable();
            $table->string('provider_refund_id', 190)->nullable()->index();
            $table->string('idempotency_key', 190)->unique();
            $table->text('reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'order_id', 'status'], 'commerce_refunds_app_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_refunds');
        Schema::dropIfExists('event_lifecycle_actions');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_status',
                'sales_paused_at',
                'cancelled_at',
                'cancelled_by_user_id',
                'postponed_at',
                'postponed_by_user_id',
                'rescheduled_at',
                'rescheduled_by_user_id',
                'previous_start_date',
                'previous_end_date',
                'refund_deadline_at',
                'lifecycle_reason',
            ]);
        });
    }
};
