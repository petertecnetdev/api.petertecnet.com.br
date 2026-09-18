<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('artist_invitation_events')) {
            Schema::create('artist_invitation_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_id')->index();
                $table->unsignedBigInteger('invitation_id')->index();
                $table->unsignedBigInteger('event_id')->index();
                $table->unsignedBigInteger('artist_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('event_type', 80)->index();
                $table->string('source', 80)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->useCurrent()->index();
                $table->timestamps();
                $table->index(['app_id','event_id','event_type','occurred_at'], 'artist_invitation_event_rollup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_invitation_events');
    }
};
