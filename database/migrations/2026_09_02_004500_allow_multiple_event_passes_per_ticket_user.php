<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_passes', function (Blueprint $table) {
            // MySQL may be using the composite unique index as the backing
            // index for the ticket_id foreign key. Create a dedicated index
            // first so the unique constraint can be removed safely.
            $table->index('ticket_id', 'event_passes_ticket_id_index');
        });

        Schema::table('event_passes', function (Blueprint $table) {
            $table->dropUnique('event_passes_ticket_id_user_id_unique');
            $table->index(['ticket_id', 'user_id'], 'event_passes_ticket_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_passes', function (Blueprint $table) {
            $table->dropIndex('event_passes_ticket_user_index');
            $table->unique(['ticket_id', 'user_id']);
        });

        Schema::table('event_passes', function (Blueprint $table) {
            $table->dropIndex('event_passes_ticket_id_index');
        });
    }
};
