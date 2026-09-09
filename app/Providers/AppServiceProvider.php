<?php

namespace App\Providers;

use App\Domain\Commerce\Http\Controllers\PendingCheckoutController;
use App\Domain\Documents\Events\DocumentSignatureRecorded;
use App\Domain\Finance\Contracts\PayoutProvider;
use App\Domain\Leasing\Listeners\SyncLeaseDocumentSignature;
use App\Events\EcosystemUpdated;
use App\Models\Application;
use App\Models\EcosystemAuditLog;
use App\Models\EcosystemSetting;
use App\Models\Establishment;
use App\Models\Event as EventModel;
use App\Models\Interaction;
use App\Models\Item;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Observers\CognitiveInteractionObserver;
use App\Observers\EstablishmentOwnershipNotificationObserver;
use App\Observers\EventFeedActivityObserver;
use App\Observers\EventProducerNotificationObserver;
use App\Observers\InteractionAuditObserver;
use App\Services\AsaasPayoutService;
use App\Services\Operations\OperationalIssueService;
use App\Services\Operations\OperationalTelemetryService;
use App\Services\Operations\ResilientOperationalIssueService;
use App\Services\Operations\ResilientOperationalTelemetryService;
use App\Services\ResilientRealtimePublisher;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
        Relation::morphMap(['establishment' => 'App\\Models\\Establishment', 'event' => 'App\\Models\\Event']);
        if ($this->app->environment('production')) {
            DB::listen(function ($query): void {
                if ($query->time < 200) return;
                Log::warning('Slow database query.', [
                    'duration_ms' => round($query->time, 1),
                    'connection' => $query->connectionName,
                    'sql' => mb_substr($query->sql, 0, 2000),
                ]);
            });
        }

        Event::listen(DocumentSignatureRecorded::class, SyncLeaseDocumentSignature::class);

        Route::prefix('api/v1/apps/{application}')
            ->middleware(['api', 'app.context', 'auth:api', 'token.version', 'app.capability:commerce'])
            ->group(function () {
                Route::get('/commerce/checkout/pending', [PendingCheckoutController::class, 'show'])
                    ->middleware('throttle:60,1');
                Route::post('/commerce/checkout/pending/recover', [PendingCheckoutController::class, 'recover'])
                    ->middleware('throttle:30,1');
            });

        $storagePath = storage_path('app/public');
        if (! File::exists($storagePath)) File::makeDirectory($storagePath, 0775, true);

        foreach ([Application::class, Profile::class, User::class, Establishment::class, Item::class, Order::class] as $auditedModel) {
            $auditedModel::observe(InteractionAuditObserver::class);
        }

        Establishment::observe(EstablishmentOwnershipNotificationObserver::class);
        EventModel::observe(EventProducerNotificationObserver::class);
        EventModel::observe(EventFeedActivityObserver::class);

        // Cognitive learning consumes the existing interaction stream and stays disabled unless COGNITION_ENABLED=true.
        Interaction::observe(CognitiveInteractionObserver::class);

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
        $publish = fn (string $action) => app(ResilientRealtimePublisher::class)->publish(
            new EcosystemUpdated($modules, $action),
            ['operation' => 'model-change', 'model' => $model, 'action' => $action]
        );

        $model::saved(fn () => $publish('saved'));
        $model::deleted(fn () => $publish('deleted'));
    }
}
