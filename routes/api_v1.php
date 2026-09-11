<?php

use App\Domain\Analytics\Http\Controllers\AppointmentDashboardController;
use App\Domain\Catalog\Http\Controllers\CatalogDiscoveryController;
use App\Domain\Catalog\Http\Controllers\EcosystemCatalogController;
use App\Domain\Commerce\Http\Controllers\EventCommerceController;
use App\Domain\Commerce\Http\Controllers\OrderHistoryController;
use App\Domain\Commerce\Http\Controllers\OrderingController;
use App\Domain\Commerce\Http\Controllers\OrderingSettingsController;
use App\Domain\Commerce\Http\Controllers\PaymentStatusController;
use App\Domain\Connections\Http\Controllers\ConnectionController;
use App\Domain\Connections\Http\Controllers\ConnectionModerationController;
use App\Domain\Connections\Http\Controllers\ConnectionPrivacyController;
use App\Domain\Contracts\Http\Controllers\ProducerAgreementController;
use App\Domain\CRM\Http\Controllers\SalesPipelineController;
use App\Domain\Discovery\Http\Controllers\GlobalSearchController;
use App\Domain\Events\Http\Controllers\EventCommunityController;
use App\Domain\Events\Http\Controllers\EventDiscoveryController;
use App\Domain\Events\Http\Controllers\EventManagementController;
use App\Domain\Events\Http\Controllers\EventSocialPreviewController;
use App\Domain\Events\Http\Controllers\EventTicketController;
use App\Domain\Finance\Http\Controllers\FinancialController;
use App\Domain\Finance\Http\Controllers\PaymentProviderController;
use App\Domain\Locations\Http\Controllers\LocationController;
use App\Domain\Organizations\Http\Controllers\OrganizationController;
use App\Domain\People\Http\Controllers\ArtistClaimController;
use App\Domain\People\Http\Controllers\ArtistMemberController;
use App\Domain\People\Http\Controllers\UserActivityProfileController;
use App\Domain\Platform\Http\Controllers\ApplicationConfigController;
use App\Domain\Platform\Http\Controllers\ModerationController;
use App\Domain\Scheduling\Http\Controllers\AppointmentWorkflowController;
use App\Domain\Scheduling\Http\Controllers\AvailabilityController;
use App\Domain\Social\Http\Controllers\FeedController;
use App\Domain\Social\Http\Controllers\SocialGraphController;
use App\Domain\Workforce\Http\Controllers\TeamMemberController;
use App\Http\Controllers\Api\V1\AccountContextController;
use App\Http\Controllers\Api\V1\ApplicationDirectoryController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider callbacks
|--------------------------------------------------------------------------
| Provider endpoints are infrastructure contracts. Application ownership is
| restored from OAuth state or the persisted financial operation.
*/
Route::prefix('v1/payments/mercadopago')->group(function () {
    Route::get('/oauth/callback', [PaymentProviderController::class, 'callback'])->middleware('throttle:60,1');
    Route::post('/webhook', [PaymentProviderController::class, 'webhook'])->middleware('throttle:240,1');
});

/*
|--------------------------------------------------------------------------
| Peter Platform API
|--------------------------------------------------------------------------
| Applications select context and capabilities only. Domain controllers never
| branch on product names and unsupported capabilities are stopped here.
*/
Route::prefix('v1/apps/{application}')
    ->middleware('app.context')
    ->group(function () {
        Route::get('/config', [ApplicationConfigController::class, 'show']);
        Route::get('/directory', [ApplicationDirectoryController::class, 'index']);
        Route::get('/global-search', [GlobalSearchController::class, 'index'])->middleware('throttle:240,1');

        // Shared infrastructure available to every application context.
        Route::get('/locations/states', [LocationController::class, 'states']);
        Route::get('/locations/cities', [LocationController::class, 'cities'])->middleware('throttle:120,1');
        Route::get('/locations/cep/{cep}', [LocationController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:60,1');

        Route::middleware('app.capability:catalog')->group(function () {
            Route::get('/discovery', [CatalogDiscoveryController::class, 'index']);
            Route::get('/search', [CatalogDiscoveryController::class, 'search']);
            Route::get('/catalog/{identifier}', [EcosystemCatalogController::class, 'showCatalog'])->where('identifier', '[A-Za-z0-9\-]+');
            Route::get('/catalog-items/{identifier}', [EcosystemCatalogController::class, 'showItem'])->where('identifier', '[A-Za-z0-9\-]+');
            Route::get('/establishments', [EstablishmentController::class, 'index']);
            Route::get('/establishments/{slug}', [EstablishmentController::class, 'show']);
            Route::get('/establishments/{establishmentSlug}/catalog', [ItemController::class, 'catalog']);
            Route::get('/items', [ItemController::class, 'index']);
        });

        Route::middleware('app.capability:commerce')->group(function () {
            Route::get('/establishments/{slug}/ordering', [OrderingController::class, 'ordering']);
            Route::post('/ordering/payments/mercadopago/webhook', [OrderingController::class, 'paymentWebhook'])->middleware('throttle:120,1');
        });

        Route::middleware('app.capability:events')->group(function () {
            Route::get('/events', [EventDiscoveryController::class, 'events']);
            Route::get('/events/facets', [EventDiscoveryController::class, 'facets']);
            Route::get('/events/public/{slug}', [EventDiscoveryController::class, 'publicEvent']);
            Route::get('/events/public/{slug}/share-preview', [EventSocialPreviewController::class, 'show']);
            Route::get('/events/public/{slug}/share-image.jpg', [EventSocialPreviewController::class, 'image']);
        });

        Route::middleware('app.capability:events,social')->group(function () {
            Route::get('/events/public/{slug}/artists', [SocialGraphController::class, 'publicEventArtists']);
        });

        Route::middleware('app.capability:events,event_community')->group(function () {
            Route::get('/events/public/{slug}/community', [EventCommunityController::class, 'publicCommunity']);
        });

        Route::middleware('app.capability:events,commerce')->group(function () {
            Route::get('/events/public/{slug}/commerce', [EventCommerceController::class, 'catalog']);
        });

        Route::middleware('app.capability:organizations')->group(function () {
            Route::get('/organizations/public', [OrganizationController::class, 'publicIndex']);
            Route::get('/organizations/public/{slug}', [OrganizationController::class, 'publicShow']);
        });

        Route::middleware('app.capability:social')->group(function () {
            Route::get('/artists', [SocialGraphController::class, 'artists']);
            Route::get('/artists/{slug}/members', [ArtistMemberController::class, 'publicIndex']);
            Route::get('/artists/{slug}', [SocialGraphController::class, 'publicArtist']);
        });

        Route::middleware(['auth:api', 'token.version'])->group(function () {
            Route::get('/me', [AccountContextController::class, 'show']);
            Route::get('/directory/companies', [ApplicationDirectoryController::class, 'companies']);
            Route::post('/directory/companies/{sourceId}/activate', [ApplicationDirectoryController::class, 'activateCompany'])->whereNumber('sourceId');
            Route::delete('/directory/companies/{sourceId}/activate', [ApplicationDirectoryController::class, 'deactivateCompany'])->whereNumber('sourceId');

            Route::middleware('app.capability:catalog')->group(function () {
                Route::get('/catalog-companies', [EcosystemCatalogController::class, 'companies']);
                Route::post('/catalog-companies/{sourceId}/activate', [EcosystemCatalogController::class, 'activate'])->whereNumber('sourceId');
                Route::get('/me/establishments', [EstablishmentController::class, 'mine']);
                Route::post('/establishments', [EstablishmentController::class, 'store']);
                Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);
                Route::delete('/establishments/{establishment}', [EstablishmentController::class, 'destroy']);
                Route::get('/establishments/{establishment}/metrics', [MetricsController::class, 'establishment']);
                Route::get('/establishments/{establishment}/items', [ItemController::class, 'mine']);
                Route::post('/items', [ItemController::class, 'store']);
                Route::patch('/items/{item}', [ItemController::class, 'update']);
                Route::delete('/items/{item}', [ItemController::class, 'destroy']);
                Route::get('/items/{item}/metrics', [MetricsController::class, 'item']);
            });

            Route::middleware('app.capability:workforce')->group(function () {
                Route::get('/team-members', [TeamMemberController::class, 'index']);
                Route::post('/team-members', [TeamMemberController::class, 'store']);
                Route::delete('/team-members/{teamMember}', [TeamMemberController::class, 'destroy'])->whereNumber('teamMember');
            });

            Route::middleware('app.capability:commerce')->group(function () {
                Route::post('/orders', [OrderingController::class, 'checkout'])->middleware('throttle:30,1');
                Route::get('/me/orders', [OrderingController::class, 'myOrders']);
                Route::get('/me/orders/{order}', [OrderingController::class, 'myOrder'])->whereNumber('order');
                Route::get('/me/orders/{order}/payment', [PaymentStatusController::class, 'show'])->whereNumber('order');
                Route::get('/establishments/{establishment}/orders', [OrderingController::class, 'establishmentOrders'])->whereNumber('establishment');
                Route::patch('/orders/{order}/status', [OrderingController::class, 'updateStatus'])->whereNumber('order');
                Route::get('/dashboard', [OrderingController::class, 'dashboard']);
                Route::get('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'show'])->whereNumber('establishment');
                Route::patch('/establishments/{establishment}/ordering-settings', [OrderingSettingsController::class, 'update'])->whereNumber('establishment');
            });

            Route::middleware('app.capability:scheduling')->group(function () {
                Route::post('/availability/times', [AvailabilityController::class, 'times']);
                Route::post('/availability/dates', [AvailabilityController::class, 'dates']);
                Route::get('/appointments/professional', [AppointmentWorkflowController::class, 'employerOrders']);
                Route::get('/appointments/{id}', [AppointmentWorkflowController::class, 'orderDetail'])->whereNumber('id');
                Route::get('/establishments/{slug}/appointments', [AppointmentWorkflowController::class, 'establishmentOrders']);
                Route::patch('/appointments/{id}/transition', [AppointmentWorkflowController::class, 'transition'])->whereNumber('id');
                Route::patch('/appointments/{id}/assign', [AppointmentWorkflowController::class, 'assign'])->whereNumber('id');
                Route::get('/users/{userName}/scheduling-profile', [AppointmentWorkflowController::class, 'userProfile']);
                Route::get('/establishments/{slug}/appointment-dashboard', [AppointmentDashboardController::class, 'overview']);
            });

            Route::middleware('app.capability:connections')->group(function () {
                Route::get('/connections/profile', [ConnectionController::class, 'profile'])->middleware('throttle:120,1');
                Route::put('/connections/profile', [ConnectionController::class, 'updateProfile'])->middleware('throttle:30,1');
                Route::post('/connections/profile/photos', [ConnectionController::class, 'uploadPhoto'])->middleware('throttle:12,1');
                Route::delete('/connections/profile/photos/{photoId}', [ConnectionController::class, 'deletePhoto'])->whereNumber('photoId')->middleware('throttle:30,1');
                Route::get('/connections/discover', [ConnectionController::class, 'discover'])->middleware('throttle:120,1');
                Route::post('/connections/decisions', [ConnectionController::class, 'swipe'])->middleware('throttle:120,1');
                Route::get('/connections/matches', [ConnectionController::class, 'matches'])->middleware('throttle:120,1');
                Route::delete('/connections/matches/{matchId}', [ConnectionController::class, 'unmatch'])->whereNumber('matchId')->middleware('throttle:30,1');
                Route::get('/connections/matches/{matchId}/messages', [ConnectionController::class, 'messages'])->whereNumber('matchId')->middleware('throttle:120,1');
                Route::post('/connections/matches/{matchId}/messages', [ConnectionController::class, 'sendMessage'])->whereNumber('matchId')->middleware('throttle:60,1');
                Route::post('/connections/users/{targetUserId}/block', [ConnectionController::class, 'block'])->whereNumber('targetUserId')->middleware('throttle:30,1');
                Route::delete('/connections/users/{targetUserId}/block', [ConnectionController::class, 'unblock'])->whereNumber('targetUserId')->middleware('throttle:30,1');
                Route::post('/connections/reports', [ConnectionController::class, 'report'])->middleware('throttle:10,1');
                Route::get('/connections/privacy/export', [ConnectionPrivacyController::class, 'export'])->middleware('throttle:5,1');
                Route::delete('/connections/privacy/profile', [ConnectionPrivacyController::class, 'destroyProfile'])->middleware('throttle:3,1');
                Route::get('/connections/moderation/reports', [ConnectionModerationController::class, 'reports'])->middleware('throttle:60,1');
                Route::post('/connections/moderation/reports/{reportId}/action', [ConnectionModerationController::class, 'act'])->whereNumber('reportId')->middleware('throttle:30,1');
            });

            Route::middleware('app.capability:crm')->group(function () {
                Route::get('/crm/context', [SalesPipelineController::class, 'contextInfo']);
                Route::get('/crm/dashboard', [SalesPipelineController::class, 'dashboard']);
                Route::get('/crm/contacts', [SalesPipelineController::class, 'contacts']);
                Route::post('/crm/contacts', [SalesPipelineController::class, 'storeContact']);
                Route::put('/crm/contacts/{id}', [SalesPipelineController::class, 'updateContact'])->whereNumber('id');
                Route::delete('/crm/contacts/{id}', [SalesPipelineController::class, 'destroyContact'])->whereNumber('id');
                Route::get('/crm/opportunities', [SalesPipelineController::class, 'opportunities']);
                Route::post('/crm/opportunities', [SalesPipelineController::class, 'storeOpportunity']);
                Route::patch('/crm/opportunities/{id}/stage', [SalesPipelineController::class, 'updateOpportunityStage'])->whereNumber('id');
                Route::get('/crm/proposals', [SalesPipelineController::class, 'proposals']);
                Route::post('/crm/proposals', [SalesPipelineController::class, 'storeProposal']);
                Route::get('/crm/charges', [SalesPipelineController::class, 'charges']);
                Route::post('/crm/charges', [SalesPipelineController::class, 'storeCharge']);
                Route::patch('/crm/charges/{id}/paid', [SalesPipelineController::class, 'markChargePaid'])->whereNumber('id');
            });

            Route::middleware('app.capability:organizations')->group(function () {
                Route::get('/organizations/mine', [OrganizationController::class, 'mine']);
                Route::get('/organizations/{id}', [OrganizationController::class, 'show'])->whereNumber('id');
                Route::post('/organizations', [OrganizationController::class, 'store']);
                Route::match(['put', 'patch'], '/organizations/{id}', [OrganizationController::class, 'update'])->whereNumber('id');
                Route::delete('/organizations/{id}', [OrganizationController::class, 'destroy'])->whereNumber('id');
            });

            Route::middleware('app.capability:agreements')->group(function () {
                Route::get('/organizations/{organizationId}/agreement', [ProducerAgreementController::class, 'show'])->whereNumber('organizationId');
                Route::post('/organizations/{organizationId}/agreement/sign', [ProducerAgreementController::class, 'sign'])->whereNumber('organizationId')->middleware('throttle:10,1');
                Route::post('/organizations/{organizationId}/agreement/resend', [ProducerAgreementController::class, 'resend'])->whereNumber('organizationId')->middleware('throttle:5,1');
                Route::get('/organizations/{organizationId}/agreement/pdf', [ProducerAgreementController::class, 'pdf'])->whereNumber('organizationId');
            });

            Route::middleware('app.capability:events')->group(function () {
                Route::get('/events/mine', [EventManagementController::class, 'mine']);
                Route::get('/events/{id}/manage', [EventManagementController::class, 'show'])->whereNumber('id');
                Route::post('/events', [EventManagementController::class, 'store'])->middleware('producer.agreement');
                Route::match(['put', 'patch'], '/events/{id}', [EventManagementController::class, 'update'])->whereNumber('id');
                Route::post('/events/{id}/publish', [EventManagementController::class, 'publish'])->whereNumber('id');
                Route::post('/events/{id}/unpublish', [EventManagementController::class, 'unpublish'])->whereNumber('id');
                Route::post('/events/{id}/duplicate', [EventManagementController::class, 'duplicate'])->whereNumber('id');
                Route::delete('/events/bulk', [EventManagementController::class, 'destroyMany']);
                Route::delete('/events/{id}', [EventManagementController::class, 'destroy'])->whereNumber('id');
            });

            Route::middleware('app.capability:event_tickets')->group(function () {
                Route::get('/events/{eventId}/tickets', [EventTicketController::class, 'index'])->whereNumber('eventId');
                Route::post('/tickets', [EventTicketController::class, 'store']);
                Route::match(['put', 'patch'], '/tickets/{ticketId}', [EventTicketController::class, 'update'])->whereNumber('ticketId');
                Route::delete('/tickets/{ticketId}', [EventTicketController::class, 'destroy'])->whereNumber('ticketId');
                Route::get('/passes/mine', [EventPassController::class, 'mine']);
                Route::get('/passes/{passId}', [EventPassController::class, 'show'])->whereNumber('passId');
                Route::post('/passes/claim/{ticketId}', [EventPassController::class, 'claim'])->whereNumber('ticketId');
                Route::get('/events/{eventId}/participants', [EventPassController::class, 'participants'])->whereNumber('eventId');
                Route::post('/checkin', [EventPassController::class, 'validateToken'])->middleware('throttle:120,1');
                Route::get('/checkin/events/{eventId}/stats', [EventPassController::class, 'eventStats'])->whereNumber('eventId');
            });

            Route::middleware('app.capability:commerce')->group(function () {
                Route::post('/commerce/checkout', [EventCommerceController::class, 'checkout'])->middleware('throttle:30,1');
                Route::get('/commerce/orders/mine', [EventCommerceController::class, 'mine']);
                Route::get('/commerce/orders/{publicId}', [EventCommerceController::class, 'show']);
                Route::post('/commerce/orders/{publicId}/sync-payment', [PaymentProviderController::class, 'sync'])->middleware('throttle:30,1');
                Route::get('/organizations/{organizationId}/payment-provider/connect', [PaymentProviderController::class, 'connect'])->whereNumber('organizationId');
                Route::post('/events/{eventId}/items', [EventCommerceController::class, 'upsertEventItem'])->whereNumber('eventId');
                Route::match(['put', 'patch'], '/events/{eventId}/items/{itemId}', [EventCommerceController::class, 'upsertEventItem'])->whereNumber('eventId')->whereNumber('itemId');
                Route::delete('/events/{eventId}/items/{itemId}', [EventCommerceController::class, 'deleteEventItem'])->whereNumber('eventId')->whereNumber('itemId');
                Route::get('/organizations/{organizationId}/payment-account', [EventCommerceController::class, 'paymentAccount'])->whereNumber('organizationId');
                Route::get('/organizations/{organizationId}/financial-summary', [EventCommerceController::class, 'financialSummary'])->whereNumber('organizationId');
                Route::get('/commerce/purchases', [OrderHistoryController::class, 'purchases']);
                Route::get('/commerce/purchases/{publicId}', [OrderHistoryController::class, 'purchase']);
                Route::get('/commerce/purchases/{publicId}/receipt', [OrderHistoryController::class, 'receipt']);
                Route::get('/commerce/purchases/{publicId}/receipt.pdf', [OrderHistoryController::class, 'receiptPdf']);
                Route::get('/organizations/{organizationId}/sales', [OrderHistoryController::class, 'organizationSales'])->whereNumber('organizationId');
                Route::get('/organizations/{organizationId}/sales/{publicId}', [OrderHistoryController::class, 'organizationSale'])->whereNumber('organizationId');
            });

            Route::middleware('app.capability:payouts')->group(function () {
                Route::get('/organizations/{organizationId}/finance', [FinancialController::class, 'overview'])->whereNumber('organizationId');
                Route::put('/organizations/{organizationId}/finance/identity', [FinancialController::class, 'saveIdentity'])->whereNumber('organizationId')->middleware('throttle:10,1');
                Route::post('/organizations/{organizationId}/finance/identity/document', [FinancialController::class, 'uploadDocument'])->whereNumber('organizationId')->middleware('throttle:10,1');
                Route::post('/organizations/{organizationId}/finance/identity/liveness-session', [FinancialController::class, 'startLiveness'])->whereNumber('organizationId')->middleware('throttle:10,1');
                Route::post('/organizations/{organizationId}/finance/identity/liveness-complete', [FinancialController::class, 'completeLiveness'])->whereNumber('organizationId')->middleware('throttle:10,1');
                Route::put('/organizations/{organizationId}/finance/pix', [FinancialController::class, 'savePix'])->whereNumber('organizationId')->middleware('throttle:5,1');
                Route::post('/organizations/{organizationId}/finance/payouts', [FinancialController::class, 'requestPayout'])->whereNumber('organizationId')->middleware('throttle:5,1');
            });

            Route::middleware('app.capability:social')->group(function () {
                Route::get('/profile/overview', [UserActivityProfileController::class, 'overview']);
                Route::get('/artists/manageable', [ArtistClaimController::class, 'manageable']);
                Route::post('/artists/provisional', [ArtistClaimController::class, 'storeProvisional']);
                Route::match(['put', 'patch'], '/artists/{artistId}/managed', [ArtistClaimController::class, 'updateManaged'])->whereNumber('artistId');
                Route::get('/artist-claims/mine', [ArtistClaimController::class, 'myClaims']);
                Route::get('/events/{eventId}/artists/{artistId}/claim', [ArtistClaimController::class, 'claimability'])->whereNumber('eventId')->whereNumber('artistId');
                Route::post('/events/{eventId}/artists/{artistId}/claim', [ArtistClaimController::class, 'claim'])->whereNumber('eventId')->whereNumber('artistId')->middleware('throttle:10,1');
                Route::get('/events/{eventId}/artist-claims', [ArtistClaimController::class, 'eventClaims'])->whereNumber('eventId');
                Route::put('/events/{eventId}/artist-claims/{claimId}', [ArtistClaimController::class, 'review'])->whereNumber('eventId')->whereNumber('claimId');
                Route::get('/artists/mine/list', [SocialGraphController::class, 'myArtists']);
                Route::post('/artists', [SocialGraphController::class, 'storeArtist']);
                Route::match(['put', 'patch'], '/artists/{id}', [SocialGraphController::class, 'updateArtist'])->whereNumber('id');
                Route::put('/artists/{artistId}/type', [ArtistMemberController::class, 'updateType'])->whereNumber('artistId');
                Route::get('/artists/{artistId}/members', [ArtistMemberController::class, 'index'])->whereNumber('artistId');
                Route::post('/artists/{artistId}/members', [ArtistMemberController::class, 'store'])->whereNumber('artistId');
                Route::match(['put', 'patch'], '/artists/{artistId}/members/{memberId}', [ArtistMemberController::class, 'update'])->whereNumber('artistId')->whereNumber('memberId');
                Route::delete('/artists/{artistId}/members/{memberId}', [ArtistMemberController::class, 'destroy'])->whereNumber('artistId')->whereNumber('memberId');
                Route::get('/events/{eventId}/artists', [SocialGraphController::class, 'eventArtists'])->whereNumber('eventId');
                Route::post('/events/{eventId}/artists', [SocialGraphController::class, 'attachArtist'])->whereNumber('eventId');
                Route::delete('/events/{eventId}/artists/{artistId}', [SocialGraphController::class, 'detachArtist'])->whereNumber('eventId')->whereNumber('artistId');
                Route::post('/social/follow', [SocialGraphController::class, 'follow']);
                Route::delete('/social/follow', [SocialGraphController::class, 'unfollow']);
                Route::match(['get', 'put'], '/social/preferences', [SocialGraphController::class, 'preferences']);
                Route::put('/events/{eventId}/engagement', [SocialGraphController::class, 'engagement'])->whereNumber('eventId');
                Route::get('/feed', [FeedController::class, 'index']);
            });

            Route::middleware('app.capability:event_community')->group(function () {
                Route::post('/events/{eventId}/community', [EventCommunityController::class, 'createPost'])->whereNumber('eventId')->middleware('throttle:30,1');
                Route::delete('/community/{postId}', [EventCommunityController::class, 'deletePost'])->whereNumber('postId');
                Route::post('/community/{postId}/like', [EventCommunityController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
                Route::delete('/community/{postId}/like', [EventCommunityController::class, 'unlike'])->whereNumber('postId');
                Route::put('/events/{eventId}/rating', [EventCommunityController::class, 'rate'])->whereNumber('eventId')->middleware('throttle:30,1');
                Route::post('/events/{eventId}/report', [EventCommunityController::class, 'report'])->whereNumber('eventId')->middleware('throttle:10,1');
            });

            Route::middleware('app.capability:notifications')->group(function () {
                Route::get('/notifications', [AppNotificationController::class, 'index']);
                Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
                Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllRead']);
                Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'markRead'])->whereNumber('id');
            });

            Route::middleware('app.capability:moderation')->group(function () {
                Route::get('/moderation/reports', [ModerationController::class, 'reports']);
                Route::put('/moderation/reports/{reportId}', [ModerationController::class, 'updateReport'])->whereNumber('reportId');
            });
        });
    });
