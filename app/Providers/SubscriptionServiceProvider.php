<?php

namespace App\Providers;

use App\Http\Controllers\SubscriptionIntentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:api'])
            ->prefix('api/v1/apps')
            ->group(function (): void {
                Route::post('/{application}/subscription-intents', [SubscriptionIntentController::class, 'store'])
                    ->where('application', '[A-Za-z0-9_-]+')
                    ->name('subscription-intents.store');
            });
    }
}
