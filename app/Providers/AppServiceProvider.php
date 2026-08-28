<?php

namespace App\Providers;

use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ApplicationContext::class, fn () => new ApplicationContext());
    }

    public function boot()
    {
        Relation::morphMap([
            'establishment' => 'App\Models\Establishment',
            'event' => 'App\Models\Event',
        ]);

        $storagePath = storage_path('app/public');
        if (! File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }
    }
}
