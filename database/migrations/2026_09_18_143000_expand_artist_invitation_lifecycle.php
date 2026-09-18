<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artist_invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('artist_invitations', 'recipient_email_encrypted')) $table->text('recipient_email_encrypted')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'email_status')) $table->string('email_status', 30)->default('not_sent')->index();
            if (! Schema::hasColumn('artist_invitations', 'email_sent_at')) $table->timestamp('email_sent_at')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'email_delivered_at')) $table->timestamp('email_delivered_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'email_opened_at')) $table->timestamp('email_opened_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'email_failed_at')) $table->timestamp('email_failed_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'last_email_error')) $table->text('last_email_error')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'send_attempts')) $table->unsignedInteger('send_attempts')->default(0);
            if (! Schema::hasColumn('artist_invitations', 'last_sent_at')) $table->timestamp('last_sent_at')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'resend_available_at')) $table->timestamp('resend_available_at')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'viewed_at')) $table->timestamp('viewed_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'decline_reason')) $table->string('decline_reason', 500)->nullable();
            if (! Schema::hasColumn('artist_invitations', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'cancelled_by_user_id')) $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'responded_by_user_id')) $table->unsignedBigInteger('responded_by_user_id')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'response_ip_hash')) $table->string('response_ip_hash', 64)->nullable();
            if (! Schema::hasColumn('artist_invitations', 'response_user_agent_hash')) $table->string('response_user_agent_hash', 64)->nullable();
            if (! Schema::hasColumn('artist_invitations', 'reminder_count')) $table->unsignedTinyInteger('reminder_count')->default(0);
            if (! Schema::hasColumn('artist_invitations', 'last_reminder_at')) $table->timestamp('last_reminder_at')->nullable()->index();
            if (! Schema::hasColumn('artist_invitations', 'important_change_count')) $table->unsignedInteger('important_change_count')->default(0);
            if (! Schema::hasColumn('artist_invitations', 'last_material_change_at')) $table->timestamp('last_material_change_at')->nullable();
            if (! Schema::hasColumn('artist_invitations', 'source')) $table->string('source', 40)->nullable()->index();
        });

        Schema::table('event_artist', function (Blueprint $table) {
            if (! Schema::hasColumn('event_artist', 'response_user_id')) $table->unsignedBigInteger('response_user_id')->nullable()->index();
            if (! Schema::hasColumn('event_artist', 'decline_reason')) $table->string('decline_reason', 500)->nullable();
            if (! Schema::hasColumn('event_artist', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('event_artist', 'last_material_change_at')) $table->timestamp('last_material_change_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_artist', function (Blueprint $table) {
            $columns = collect(['response_user_id','decline_reason','cancelled_at','last_material_change_at'])
                ->filter(fn ($column) => Schema::hasColumn('event_artist', $column))->all();
            if ($columns !== []) $table->dropColumn($columns);
        });

        Schema::table('artist_invitations', function (Blueprint $table) {
            $columns = collect([
                'recipient_email_encrypted','email_status','email_sent_at','email_delivered_at','email_opened_at','email_failed_at',
                'last_email_error','send_attempts','last_sent_at','resend_available_at','viewed_at','decline_reason','cancelled_at',
                'cancelled_by_user_id','responded_by_user_id','response_ip_hash','response_user_agent_hash','reminder_count',
                'last_reminder_at','important_change_count','last_material_change_at','source',
            ])->filter(fn ($column) => Schema::hasColumn('artist_invitations', $column))->all();
            if ($columns !== []) $table->dropColumn($columns);
        });
    }
};
