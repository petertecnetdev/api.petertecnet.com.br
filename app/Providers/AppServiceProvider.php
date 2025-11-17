<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

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
            'event'         => 'App\Models\Event',
        ]);

        // 🔹 Garante apenas que a pasta existe — sem chmod
        $storagePath = storage_path('app/public');
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }
    }
}
