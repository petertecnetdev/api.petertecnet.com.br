<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation; // ← import
use App\Models\Barber;
use App\Models\User;
use App\Observers\BarberObserver;
use App\Observers\UserObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // 1) Ajuste de permissões em storage (seu código já existente)
        $storagePath = storage_path('app/public');
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }
        File::chmod($storagePath, 0775);
        $this->setPermissionsRecursively($storagePath);

        // 2) Mapear nomes polimórficos das entidades
        Relation::morphMap([
            'barbearia' => \App\Models\Barbershop::class,
            // adicione aqui novas entidades...
        ]);

        // 3) Observers
        Barber::observe(BarberObserver::class);
        User::observe(UserObserver::class);
    }

    protected function setPermissionsRecursively($path)
    {
        if (File::exists($path)) {
            foreach (File::directories($path) as $directory) {
                File::chmod($directory, 0775);
                $this->setPermissionsRecursively($directory);
            }
        }
    }
}
