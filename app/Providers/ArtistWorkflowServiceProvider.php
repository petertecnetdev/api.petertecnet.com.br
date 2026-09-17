<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ArtistWorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/artist_workflow.php'));
    }
}
