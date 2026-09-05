<?php

use App\Domain\Analytics\Http\Controllers\AppointmentDashboardController;
use App\Domain\Catalog\Http\Controllers\CatalogDiscoveryController;
use App\Domain\Catalog\Http\Controllers\EcosystemCatalogController;
use App\Domain\Commerce\Http\Controllers\EventCommerceController;
use App\Domain\Commerce\Http\Controllers\OrderHistoryController;
use App\Domain\Connections\Http\Controllers\ConnectionController;
use App\Domain\Connections\Http\Controllers\ConnectionModerationController;
use App\Domain\Connections\Http\Controllers\ConnectionPrivacyController;
use App\Domain\Contracts\Http\Controllers\ProducerAgreementController;
use App\Domain\CRM\Http\Controllers\SalesPipelineController;
use App\Domain\Events\Http\Controllers\EventCommunityController;
use App\Domain\Events\Http\Controllers\EventDiscoveryController;
use App\Domain\Events\Http\Controllers\EventManagementController;
use App\Domain\Events\Http\Controllers\EventPassTransferController;
use App\Domain\Events\Http\Controllers\EventTicketController;
use App\Domain\Finance\Http\Controllers\PaymentProviderController;
use App\Domain\Finance\Http\Controllers\PayoutController;
use App\Domain\Locations\Http\Controllers\LocationController;
use App\Domain\People\Http\Controllers\ArtistClaimController;
use App\Domain\People\Http\Controllers\ArtistMemberController;
use App\Domain\People\Http\Controllers\UserActivityProfileController;
use App\Domain\Platform\Http\Controllers\ModerationController;
use App\Domain\Scheduling\Http\Controllers\AppointmentWorkflowController;
use App\Domain\Scheduling\Http\Controllers\AvailabilityController;
use App\Domain\Social\Http\Controllers\FeedController;
use App\Domain\Social\Http\Controllers\SocialGraphController;
use App\Domain\Workforce\Http\Controllers\TeamMemberController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\CompatibilityContractController;
use App\Http\Controllers\EventPassController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Temporary API compatibility boundary
|--------------------------------------------------------------------------
| These aliases keep already-deployed clients online while they are migrated
| to /api/v1/apps/{application}. No business logic is allowed here and every
| route delegates to the same generic domain controllers used by V1.
|
| Removal rule: an alias may be deleted only after compatibility-route logs
| show zero requests for the path throughout the agreed observation window.
*/

$publicCompatibility = static function (string $application, callable $routes): void {
    Route::prefix($application)
        ->middleware(['api', 'app.bind:'.$application, 'compatibility.route'])
        ->group($routes);
};

$authenticatedCompatibility = static function (string $application, callable $routes): void {
    Route::prefix($application)
        ->middleware(['api', 'app.bind:'.$application, 'compatibility.route', 'auth:api', 'token.version'])
        ->group($routes);
};

// Event/experience client compatibility.
$publicCompatibility('cutinapp', static function (): void {
    Route::get('/config', [CompatibilityContractController::class, 'config']);
    Route::get('/locations/states', [LocationController::class, 'states']);
    Route::get('/locations/cities', [LocationController::class, 'cities'])->middleware('throttle:120,1');
    Route::get('/locations/cep/{cep}', [LocationController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:60,1');
    Route::get('/events', [EventDiscoveryController::class, 'events']);
    Route::get('/discovery/facets', [EventDiscoveryController::class, 'facets']);
    Route::get('/events/public/{slug}', [EventDiscoveryController::class, 'publicEvent']);
    Route::get('/events/public/{slug}/artists', [SocialGraphController::class, 'publicEventArtists']);
    Route::get('/events/public/{slug}/community', [EventCommunityController::class, 'publicCommunity']);
    Route::get('/events/public/{slug}/commerce', [EventCommerceController::class, 'catalog']);
    Route::get('/payments/mercadopago/oauth/callback', [PaymentProviderController::class, 'callback'])->middleware('throttle:60,1');
    Route::post('/payments/mercadopago/webhook', [PaymentProviderController::class, 'webhook'])->middleware('throttle:240,1');
    Route::get('/artists', [SocialGraphController::class, 'artists']);
    Route::get('/artists/{slug}/members', [ArtistMemberController::class, 'publicIndex']);
    Route::get('/artists/{slug}', [SocialGraphController::class, 'publicArtist']);
    Route::get('/productions/public', [CompatibilityContractController::class, 'publicOrganizations']);
    Route::get('/productions/public/{slug}', [CompatibilityContractController::class, 'publicOrganization']);
});

$authenticatedCompatibility('cutinapp', static function (): void {
    Route::get('/profile/overview', [UserActivityProfileController::class, 'overview']);

    Route::post('/checkout', [EventCommerceController::class, 'checkout'])->middleware('throttle:30,1');
    Route::get('/orders/mine', [EventCommerceController::class, 'mine']);
    Route::get('/orders/{publicId}', [EventCommerceController::class, 'show']);
    Route::post('/orders/{publicId}/sync-payment', [PaymentProviderController::class, 'sync'])->middleware('throttle:30,1');

    Route::get('/productions/{organizationId}/mercadopago/connect', [PaymentProviderController::class, 'connect'])->whereNumber('organizationId');
    Route::get('/productions/{organizationId}/contract', [ProducerAgreementController::class, 'show'])->whereNumber('organizationId');
    Route::post('/productions/{organizationId}/contract/sign', [ProducerAgreementController::class, 'sign'])->whereNumber('organizationId')->middleware('throttle:10,1');
    Route::post('/productions/{organizationId}/contract/resend', [ProducerAgreementController::class, 'resend'])->whereNumber('organizationId')->middleware('throttle:5,1');
    Route::get('/productions/{organizationId}/contract/pdf', [ProducerAgreementController::class, 'pdf'])->whereNumber('organizationId');

    Route::post('/events/{eventId}/items', [EventCommerceController::class, 'upsertEventItem'])->whereNumber('eventId');
    Route::match(['put', 'post'], '/events/{eventId}/items/{itemId}', [EventCommerceController::class, 'upsertEventItem'])->whereNumber('eventId')->whereNumber('itemId');
    Route::delete('/events/{eventId}/items/{itemId}', [EventCommerceController::class, 'deleteEventItem'])->whereNumber('eventId')->whereNumber('itemId');
    Route::get('/productions/{organizationId}/payment-account', [EventCommerceController::class, 'paymentAccount'])->whereNumber('organizationId');
    Route::get('/productions/{organizationId}/financial-summary', [EventCommerceController::class, 'financialSummary'])->whereNumber('organizationId');
    Route::get('/productions/{organizationId}/payouts', [PayoutController::class, 'summary'])->whereNumber('organizationId');
    Route::post('/productions/{organizationId}/payouts', [PayoutController::class, 'requestPayout'])->whereNumber('organizationId')->middleware('throttle:10,1');
    Route::post('/productions/{organizationId}/payouts/{payoutId}/cancel', [PayoutController::class, 'cancel'])->whereNumber('organizationId')->whereNumber('payoutId');

    Route::get('/productions/mine', [CompatibilityContractController::class, 'myOrganizations']);
    Route::get('/productions/{id}', [CompatibilityContractController::class, 'showOrganization'])->whereNumber('id');
    Route::post('/productions', [CompatibilityContractController::class, 'storeOrganization']);
    Route::match(['post', 'put'], '/productions/{id}', [CompatibilityContractController::class, 'updateOrganization'])->whereNumber('id');

    Route::get('/events/mine', [EventManagementController::class, 'mine']);
    Route::get('/events/show/{id}', [EventManagementController::class, 'show'])->whereNumber('id');
    Route::post('/events', [EventManagementController::class, 'store'])->middleware('producer.agreement');
    Route::match(['post', 'put'], '/events/{id}', [EventManagementController::class, 'update'])->whereNumber('id');
    Route::post('/events/{id}/publish', [EventManagementController::class, 'publish'])->whereNumber('id');
    Route::post('/events/{id}/unpublish', [EventManagementController::class, 'unpublish'])->whereNumber('id');

    Route::post('/events/{eventId}/community', [EventCommunityController::class, 'createPost'])->whereNumber('eventId')->middleware('throttle:30,1');
    Route::delete('/community/{postId}', [EventCommunityController::class, 'deletePost'])->whereNumber('postId');
    Route::post('/community/{postId}/like', [EventCommunityController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
    Route::delete('/community/{postId}/like', [EventCommunityController::class, 'unlike'])->whereNumber('postId');
    Route::put('/events/{eventId}/rating', [EventCommunityController::class, 'rate'])->whereNumber('eventId')->middleware('throttle:30,1');
    Route::post('/events/{eventId}/report', [EventCommunityController::class, 'report'])->whereNumber('eventId')->middleware('throttle:10,1');

    Route::get('/artists/manageable', [ArtistClaimController::class, 'manageable']);
    Route::post('/artists/provisional', [ArtistClaimController::class, 'storeProvisional']);
    Route::match(['post', 'put'], '/artists/{artistId}/managed', [ArtistClaimController::class, 'updateManaged'])->whereNumber('artistId');
    Route::get('/artist-claims/mine', [ArtistClaimController::class, 'myClaims']);
    Route::get('/events/{eventId}/artists/{artistId}/claim', [ArtistClaimController::class, 'claimability'])->whereNumber('eventId')->whereNumber('artistId');
    Route::post('/events/{eventId}/artists/{artistId}/claim', [ArtistClaimController::class, 'claim'])->whereNumber('eventId')->whereNumber('artistId')->middleware('throttle:10,1');
    Route::get('/events/{eventId}/artist-claims', [ArtistClaimController::class, 'eventClaims'])->whereNumber('eventId');
    Route::put('/events/{eventId}/artist-claims/{claimId}', [ArtistClaimController::class, 'review'])->whereNumber('eventId')->whereNumber('claimId');

    Route::get('/artists/mine/list', [SocialGraphController::class, 'myArtists']);
    Route::post('/artists', [SocialGraphController::class, 'storeArtist']);
    Route::match(['post', 'put'], '/artists/{id}', [SocialGraphController::class, 'updateArtist'])->whereNumber('id');
    Route::put('/artists/{artistId}/type', [ArtistMemberController::class, 'updateType'])->whereNumber('artistId');
    Route::get('/artists/{artistId}/members', [ArtistMemberController::class, 'index'])->whereNumber('artistId');
    Route::post('/artists/{artistId}/members', [ArtistMemberController::class, 'store'])->whereNumber('artistId');
    Route::match(['post', 'put'], '/artists/{artistId}/members/{memberId}', [ArtistMemberController::class, 'update'])->whereNumber('artistId')->whereNumber('memberId');
    Route::delete('/artists/{artistId}/members/{memberId}', [ArtistMemberController::class, 'destroy'])->whereNumber('artistId')->whereNumber('memberId');
    Route::get('/events/{eventId}/artists', [SocialGraphController::class, 'eventArtists'])->whereNumber('eventId');
    Route::post('/events/{eventId}/artists', [SocialGraphController::class, 'attachArtist'])->whereNumber('eventId');
    Route::delete('/events/{eventId}/artists/{artistId}', [SocialGraphController::class, 'detachArtist'])->whereNumber('eventId')->whereNumber('artistId');
    Route::post('/follow', [SocialGraphController::class, 'follow']);
    Route::delete('/follow', [SocialGraphController::class, 'unfollow']);
    Route::match(['get', 'put'], '/preferences', [SocialGraphController::class, 'preferences']);
    Route::put('/events/{eventId}/engagement', [SocialGraphController::class, 'engagement'])->whereNumber('eventId');
    Route::get('/feed', [FeedController::class, 'index']);

    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::post('/notifications/read-all', [AppNotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notificationId}/read', [AppNotificationController::class, 'markRead'])->whereNumber('notificationId');
    Route::get('/moderation/reports', [ModerationController::class, 'reports']);
    Route::put('/moderation/reports/{reportId}', [ModerationController::class, 'updateReport'])->whereNumber('reportId');

    Route::post('/tickets', [EventTicketController::class, 'store']);
    Route::post('/courtesies', [CompatibilityContractController::class, 'storeCourtesy']);
    Route::get('/events/{eventId}/courtesies', [EventTicketController::class, 'index'])->whereNumber('eventId');
    Route::match(['post', 'put'], '/courtesies/{ticketId}', [EventTicketController::class, 'update'])->whereNumber('ticketId');
    Route::delete('/courtesies/{ticketId}', [EventTicketController::class, 'destroy'])->whereNumber('ticketId');
    Route::get('/passes/mine', [EventPassController::class, 'mine']);
    Route::get('/passes/{passId}', [EventPassController::class, 'show'])->whereNumber('passId');
    Route::post('/passes/{passId}/transfer', [EventPassTransferController::class, 'transfer'])->whereNumber('passId')->middleware('throttle:10,1');
    Route::post('/event-passes/{passId}/transfer', [EventPassTransferController::class, 'transfer'])->whereNumber('passId')->middleware('throttle:10,1');
    Route::get('/events/{eventId}/participants', [EventPassController::class, 'participants'])->whereNumber('eventId');
    Route::post('/passes/claim/{ticketId}', [EventPassController::class, 'claim'])->whereNumber('ticketId');
    Route::post('/checkin', [EventPassController::class, 'validateToken'])->middleware('throttle:120,1');
    Route::get('/checkin/event/{eventId}/stats', [EventPassController::class, 'eventStats'])->whereNumber('eventId');

    Route::get('/purchases', [OrderHistoryController::class, 'purchases']);
    Route::get('/purchases/{publicId}', [OrderHistoryController::class, 'purchase']);
    Route::get('/purchases/{publicId}/receipt', [OrderHistoryController::class, 'receipt']);
    Route::get('/purchases/{publicId}/receipt.pdf', [OrderHistoryController::class, 'receiptPdf']);
    Route::get('/productions/{organizationId}/sales', [OrderHistoryController::class, 'producerSales'])->whereNumber('organizationId');
    Route::get('/productions/{organizationId}/sales/{publicId}', [OrderHistoryController::class, 'producerSale'])->whereNumber('organizationId');
});

// Scheduling client compatibility.
Route::prefix('employer')
    ->middleware(['api', 'app.bind:rasoio', 'compatibility.route', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::post('/available-times', [AvailabilityController::class, 'times']);
        Route::post('/store', [TeamMemberController::class, 'store']);
    });

$authenticatedCompatibility('rasoio', static function (): void {
    Route::post('/employers', [TeamMemberController::class, 'store']);
    Route::get('/orders/employer', [AppointmentWorkflowController::class, 'employerOrders']);
    Route::get('/orders/{id}', [AppointmentWorkflowController::class, 'orderDetail'])->whereNumber('id');
    Route::get('/establishments/{slug}/orders', [AppointmentWorkflowController::class, 'establishmentOrders']);
    Route::get('/establishments/{slug}/overview', [AppointmentDashboardController::class, 'overview']);
    Route::patch('/orders/{id}/transition', [AppointmentWorkflowController::class, 'transition'])->whereNumber('id');
    Route::patch('/orders/{id}/assign', [AppointmentWorkflowController::class, 'assign'])->whereNumber('id');
    Route::get('/users/{userName}', [AppointmentWorkflowController::class, 'userProfile']);
    Route::post('/availability/times', [AvailabilityController::class, 'times']);
    Route::post('/availability/dates', [AvailabilityController::class, 'dates']);
    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
    Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [AppNotificationController::class, 'markRead'])->whereNumber('id');
});

// Catalog client compatibility.
$publicCompatibility('nexus', static function (): void {
    Route::get('/discovery', [CatalogDiscoveryController::class, 'index']);
    Route::get('/search', [CatalogDiscoveryController::class, 'search']);
    Route::get('/catalog/{identifier}', [EcosystemCatalogController::class, 'showCatalog'])->where('identifier', '[A-Za-z0-9\-]+');
    Route::get('/item/{identifier}', [EcosystemCatalogController::class, 'showItem'])->where('identifier', '[A-Za-z0-9\-]+');
});
$authenticatedCompatibility('nexus', static function (): void {
    Route::get('/catalog-companies', [EcosystemCatalogController::class, 'companies']);
    Route::post('/catalog-companies/{sourceId}/activate', [EcosystemCatalogController::class, 'activate'])->whereNumber('sourceId');
});

// Connection/matching client compatibility.
$authenticatedCompatibility('laora', static function (): void {
    Route::get('/profile', [ConnectionController::class, 'profile'])->middleware('throttle:120,1');
    Route::put('/profile', [ConnectionController::class, 'updateProfile'])->middleware('throttle:30,1');
    Route::post('/profile/photos', [ConnectionController::class, 'uploadPhoto'])->middleware('throttle:12,1');
    Route::delete('/profile/photos/{photoId}', [ConnectionController::class, 'deletePhoto'])->whereNumber('photoId')->middleware('throttle:30,1');
    Route::get('/discover', [ConnectionController::class, 'discover'])->middleware('throttle:120,1');
    Route::post('/swipes', [ConnectionController::class, 'swipe'])->middleware('throttle:120,1');
    Route::get('/matches', [ConnectionController::class, 'matches'])->middleware('throttle:120,1');
    Route::delete('/matches/{matchId}', [ConnectionController::class, 'unmatch'])->whereNumber('matchId')->middleware('throttle:30,1');
    Route::get('/matches/{matchId}/messages', [ConnectionController::class, 'messages'])->whereNumber('matchId')->middleware('throttle:120,1');
    Route::post('/matches/{matchId}/messages', [ConnectionController::class, 'sendMessage'])->whereNumber('matchId')->middleware('throttle:60,1');
    Route::post('/users/{targetUserId}/block', [ConnectionController::class, 'block'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::delete('/users/{targetUserId}/block', [ConnectionController::class, 'unblock'])->whereNumber('targetUserId')->middleware('throttle:30,1');
    Route::post('/reports', [ConnectionController::class, 'report'])->middleware('throttle:10,1');
    Route::get('/privacy/export', [ConnectionPrivacyController::class, 'export'])->middleware('throttle:5,1');
    Route::delete('/privacy/profile', [ConnectionPrivacyController::class, 'destroyProfile'])->middleware('throttle:3,1');
    Route::get('/admin/reports', [ConnectionModerationController::class, 'reports'])->middleware('throttle:60,1');
    Route::post('/admin/reports/{reportId}/action', [ConnectionModerationController::class, 'act'])->whereNumber('reportId')->middleware('throttle:30,1');
});

// CRM client compatibility.
$authenticatedCompatibility('payflow', static function (): void {
    Route::get('/context', [SalesPipelineController::class, 'contextInfo']);
    Route::get('/dashboard', [SalesPipelineController::class, 'dashboard']);
    Route::get('/contacts', [SalesPipelineController::class, 'contacts']);
    Route::post('/contacts', [SalesPipelineController::class, 'storeContact']);
    Route::put('/contacts/{id}', [SalesPipelineController::class, 'updateContact'])->whereNumber('id');
    Route::delete('/contacts/{id}', [SalesPipelineController::class, 'destroyContact'])->whereNumber('id');
    Route::get('/opportunities', [SalesPipelineController::class, 'opportunities']);
    Route::post('/opportunities', [SalesPipelineController::class, 'storeOpportunity']);
    Route::patch('/opportunities/{id}/stage', [SalesPipelineController::class, 'updateOpportunityStage'])->whereNumber('id');
    Route::get('/proposals', [SalesPipelineController::class, 'proposals']);
    Route::post('/proposals', [SalesPipelineController::class, 'storeProposal']);
    Route::get('/charges', [SalesPipelineController::class, 'charges']);
    Route::post('/charges', [SalesPipelineController::class, 'storeCharge']);
    Route::patch('/charges/{id}/paid', [SalesPipelineController::class, 'markChargePaid'])->whereNumber('id');
});
