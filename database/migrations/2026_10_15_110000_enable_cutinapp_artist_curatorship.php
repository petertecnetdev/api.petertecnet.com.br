<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('application_admin_profiles')) {
            return;
        }

        $applicationId = DB::table('applications')->where('slug', 'cutinapp')->value('id');
        if (! $applicationId) {
            return;
        }

        // Preserva as autorizações existentes; acrescenta somente curadoria artística
        // ao perfil operacional que já está atribuído pela administração da Cutinapp.
        $profile = DB::table('application_admin_profiles')
            ->where('application_id', $applicationId)
            ->where('slug', 'cutinapp-admin-full')
            ->first();

        if (! $profile) {
            return;
        }

        $permissions = json_decode((string) $profile->permissions, true);
        if (! is_array($permissions) || in_array('artists.manage', $permissions, true)) {
            return;
        }

        $permissions[] = 'artists.manage';
        DB::table('application_admin_profiles')
            ->where('id', $profile->id)
            ->where('application_id', $applicationId)
            ->update(['permissions' => json_encode(array_values(array_unique($permissions))), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Uma reversão não revoga papéis ativos nem permissões concedidas após a implantação.
    }
};
