<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ArtistWorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api/v1/apps/{application}')
            ->middleware(['api', 'app.context'])
            ->group(base_path('routes/artist_workflow.php'));
    }
}
