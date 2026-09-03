<?php

namespace App\Providers;

use App\Events\EcosystemUpdated;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Observers\InteractionAuditObserver;
use App\Support\ApplicationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ApplicationContext::class, fn () => new ApplicationContext());
    }

    public function boot()
    {
        $this->configureIdentityRateLimiters();

        Relation::morphMap([
            'establishment' => 'App\Models\Establishment',
            'event' => 'App\Models\Event',
        ]);

        $storagePath = storage_path('app/public');
        if (! File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0775, true);
        }

        foreach ([Application::class, Profile::class, User::class, Establishment::class, Item::class, Order::class] as $auditedModel) {
            $auditedModel::observe(InteractionAuditObserver::class);
        }

        $this->broadcastModelChanges(Interaction::class, ['dashboard', 'activity', 'audit']);
        $this->broadcastModelChanges(Order::class, ['dashboard', 'activity', 'audit']);
        $this->broadcastModelChanges(User::class, ['dashboard', 'users', 'activity', 'audit']);
        $this->broadcastModelChanges(Application::class, ['dashboard', 'applications', 'users', 'audit']);
        $this->broadcastModelChanges(Profile::class, ['dashboard', 'profiles', 'users', 'audit']);
        $this->broadcastModelChanges(Establishment::class, ['dashboard', 'establishments', 'users', 'audit']);
        $this->broadcastModelChanges(EcosystemSetting::class, ['site', 'audit']);
        $this->broadcastModelChanges(EcosystemAuditLog::class, ['dashboard', 'audit']);
    }

    private function configureIdentityRateLimiters(): void
    {
        RateLimiter::for('identity-exchange', function (Request $request) {
            $app = strtolower(trim((string) $request->header('X-Peter-App', 'unknown')));
            return [
                Limit::perMinute(60)->by('identity-exchange:'.$request->ip().':'.$app),
                Limit::perHour(600)->by('identity-exchange-hour:'.$request->ip()),
            ];
        });

        RateLimiter::for('identity-session', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(120)->by('identity-session:'.$actor);
        });

        RateLimiter::for('identity-security', function (Request $request) {
            $actor = $request->user()?->id ?: $request->ip();
            return [
                Limit::perMinute(20)->by('identity-security:'.$actor),
                Limit::perHour(120)->by('identity-security-hour:'.$actor),
            ];
        });
    }

    private function broadcastModelChanges(string $model, array $modules): void
    {
        $model::saved(fn () => broadcast(new EcosystemUpdated($modules, 'saved')));
        $model::deleted(fn () => broadcast(new EcosystemUpdated($modules, 'deleted')));
    }
}
