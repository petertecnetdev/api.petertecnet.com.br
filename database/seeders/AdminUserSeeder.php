<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('peter.admin_email')));

        if ($email === '') {
            $this->command?->warn('PETER_ADMIN_EMAIL não está configurado; nenhuma conta recebeu privilégios administrativos.');
            return;
        }

        $profile = Profile::query()->where('name', 'Administrador')->firstOrFail();
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $this->command?->warn('A conta administrativa configurada ainda não existe; nenhuma promoção foi realizada.');
            return;
        }

        if ((int) $user->profile_id === (int) $profile->id) {
            return;
        }

        $user->forceFill(['profile_id' => $profile->id])->saveQuietly();
        $this->command?->info('Perfil administrativo aplicado à conta configurada.');
    }
}
