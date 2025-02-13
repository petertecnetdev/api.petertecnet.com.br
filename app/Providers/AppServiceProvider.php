<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use App\Models\Barber;
use App\Models\User;
use App\Observers\BarberObserver;
use App\Observers\UserObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Defina um caminho base para a pasta de storage
        $storagePath = storage_path('app/public');

        // Cria o diretório, se não existir, com permissões 0775
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }

        // Aplique as permissões 775 a todos os diretórios e arquivos dentro de 'storage/app/public'
        // Isso garante que novos diretórios criados herdem as permissões corretas
        File::chmod($storagePath, 0775);

        // Caso você tenha subdiretórios específicos para usuários ou outros tipos, garanta as permissões deles
        $this->setPermissionsRecursively($storagePath);

        Barber::observe(BarberObserver::class);
        User::observe(UserObserver::class);
    }

    /**
     * Aplica permissões recursivamente a todos os diretórios dentro de um diretório especificado.
     *
     * @param string $path
     */
    protected function setPermissionsRecursively($path)
    {
        // Verifica se o diretório existe
        if (File::exists($path)) {
            // Percorre todos os diretórios dentro do caminho fornecido
            $directories = File::directories($path);

            foreach ($directories as $directory) {
                // Aplica permissões 775 a cada subdiretório
                File::chmod($directory, 0775);

                // Chama recursivamente para os subdiretórios
                $this->setPermissionsRecursively($directory);
            }
        }
    }
}
