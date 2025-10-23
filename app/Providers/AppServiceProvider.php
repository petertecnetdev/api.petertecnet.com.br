<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\User;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // 🔹 Corrige o erro do morphTo (mapeia nomes simples para classes)
        Relation::morphMap([
            'establishment' => 'App\Models\Establishment',
            'event' => 'App\Models\Event', // opcional, caso use eventos
        ]);

        // 🔹 Ajuste de permissões em storage (mantido do seu código atual)
        $storagePath = storage_path('app/public');
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }
        File::chmod($storagePath, 0775);
        $this->setPermissionsRecursively($storagePath);
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
