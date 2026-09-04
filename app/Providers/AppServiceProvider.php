<?php

namespace App\Providers;

use App\Domain\Documents\Events\DocumentSignatureRecorded;
use App\Domain\Finance\Contracts\PayoutProvider;
use App\Domain\Leasing\Listeners\SyncLeaseDocumentSignature;
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
use App\Services\AsaasPayoutService;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use App\Services\Operations\ResilientOperationalIssueService;
use App\Services\Operations\ResilientOperationalTelemetryService;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ApplicationContext::class, fn () => new ApplicationContext());

        $this->app->bind(OperationalTelemetryService::class, ResilientOperationalTelemetryService::class);
        $this->app->bind(OperationalIssueService::class, ResilientOperationalIssueService::class);

        $this->app->bind(PayoutProvider::class, function ($app) {
            return match (mb_strtolower((string) config('services.finance.payout_provider', 'asaas'))) {
                'asaas' => $app->make(AsaasPayoutService::class),
                default => throw new LogicException('O provider de payout configurado não possui adapter registrado.'),
            };
        });
    }

    public function boot()
    {
        Relation::morphMap([
            'establishment' => 'App\Models\Establishment',
            'event' => 'App\Models\Event',
        ]);

        Event::listen(DocumentSignatureRecorded::class, SyncLeaseDocumentSignature::class);

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
