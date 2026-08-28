<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('peter.admin_email');
        $profile = Profile::query()->where('name', 'Administrador')->firstOrFail();
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if (!$user) {
            $this->command?->info("Conta administrativa reservada para {$email}; ela receberá o perfil ao ser cadastrada.");
            return;
        }

        $user->forceFill(['profile_id' => $profile->id])->save();
    }
}
