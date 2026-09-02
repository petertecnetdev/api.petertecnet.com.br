<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('application_user') || ! Schema::hasTable('application_accesses')) {
            return;
        }

        DB::table('application_user')
            ->orderBy('user_id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('application_accesses')->updateOrInsert(
                        [
                            'application_id' => $row->application_id,
                            'user_id' => $row->user_id,
                            'organization_id' => null,
                        ],
                        [
                            'role' => $row->role ?: 'member',
                            'scopes' => json_encode(['profile.read'], JSON_UNESCAPED_UNICODE),
                            'status' => $row->status ?: 'active',
                            'granted_at' => $row->joined_at ?: now(),
                            'created_at' => $row->created_at ?: now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        // Canonical access rows may have been legitimately updated after the
        // backfill, so rollback deliberately does not delete them.
    }
};
