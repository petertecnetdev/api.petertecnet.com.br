<?php

namespace Database\Seeders;

use App\Models\Profile;
use Illuminate\Database\Seeder;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissions = array_keys(config('permissions', []));

        $profiles = [
            'Administrador' => $allPermissions,
            'Gestor' => ['application_manage'],
            'Usuário' => [],
        ];

        foreach ($profiles as $name => $permissions) {
            Profile::query()->updateOrCreate(
                ['name' => $name],
                ['permissions' => array_values($permissions)]
            );
        }
    }
}
