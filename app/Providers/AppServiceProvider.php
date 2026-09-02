<?php

namespace App\Providers;

use App\Domain\Commerce\Contracts\PaymentProviderInterface;
use App\Events\EcosystemUpdated;
use App\Infrastructure\Payments\MercadoPagoPaymentProvider;
use App\Infrastructure\Products\Cutinapp\Observers\EventObserver as CutinappEventObserver;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Event;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Observers\InteractionAuditObserver;
use App\Support\ActorContext;
use App\Support\ApplicationContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ApplicationContext::class, fn () => new ApplicationContext());
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
        $this->app->singleton(ActorContext::class, fn () => new ActorContext());
        $this->app->bind(PaymentProviderInterface::class, MercadoPagoPaymentProvider::class);
    }

    public function boot()
    {
        Relation::morphMap([
            'establishment' => Establishment::class,
            'event' => Event::class,
        ]);

        // Product hooks are infrastructure adapters; the canonical Event model stays product-agnostic.
        Event::observe(CutinappEventObserver::class);

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

    private function broadcastModelChanges(string $model, array $modules): void
    {
        $model::saved(fn () => broadcast(new EcosystemUpdated($modules, 'saved')));
        $model::deleted(fn () => broadcast(new EcosystemUpdated($modules, 'deleted')));
    }
}
