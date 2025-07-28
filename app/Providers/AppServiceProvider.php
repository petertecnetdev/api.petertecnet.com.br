<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use App\Models\User;

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
