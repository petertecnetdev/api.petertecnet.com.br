<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformArchitectureTest extends TestCase
{
    private const PRODUCT_PREFIX = '/^(Cutinapp|Rasoio|Nexus|Plat|Laora|Payflow|PayFlow)/i';

    /**
     * Historical debt that is allowed to exist only while it is being migrated.
     * Adding a new product-prefixed class requires changing this test and must be
     * rejected in review. Entries may only be removed from this list over time.
     */
    private const LEGACY_CLASS_ALLOWLIST = [
        'app/Console/Commands/CutinappEventReminderCommand.php',
        'app/Console/Commands/CutinappReconcilePaymentsCommand.php',
        'app/Http/Controllers/Api/V1/PlatOrderController.php',
        'app/Http/Controllers/Api/V1/PlatOrderingSettingsController.php',
        'app/Http/Controllers/Api/V1/PlatPaymentController.php',
        'app/Http/Controllers/CutinappArtistClaimController.php',
        'app/Http/Controllers/CutinappArtistMemberController.php',
        'app/Http/Controllers/CutinappCommerceController.php',
        'app/Http/Controllers/CutinappContractProtectedEventController.php',
        'app/Http/Controllers/CutinappController.php',
        'app/Http/Controllers/CutinappCourtesyController.php',
        'app/Http/Controllers/CutinappDiscoveryController.php',
        'app/Http/Controllers/CutinappEventCommunityController.php',
        'app/Http/Controllers/CutinappEventController.php',
        'app/Http/Controllers/CutinappFeedController.php',
        'app/Http/Controllers/CutinappLocationController.php',
        'app/Http/Controllers/CutinappMercadoPagoController.php',
        'app/Http/Controllers/CutinappModerationController.php',
        'app/Http/Controllers/CutinappNotificationController.php',
        'app/Http/Controllers/CutinappOrderHistoryController.php',
        'app/Http/Controllers/CutinappPassClaimController.php',
        'app/Http/Controllers/CutinappPayoutController.php',
        'app/Http/Controllers/CutinappProducerContractController.php',
        'app/Http/Controllers/CutinappPublicProductionController.php',
        'app/Http/Controllers/CutinappPublicSocialController.php',
        'app/Http/Controllers/CutinappSocialController.php',
        'app/Http/Controllers/CutinappTicketController.php',
        'app/Http/Controllers/CutinappUserProfileController.php',
        'app/Http/Controllers/LaoraController.php',
        'app/Http/Controllers/LaoraModerationController.php',
        'app/Http/Controllers/LaoraPrivacyController.php',
        'app/Http/Controllers/NexusCatalogCompanyController.php',
        'app/Http/Controllers/NexusDiscoveryController.php',
        'app/Http/Controllers/PayflowController.php',
        'app/Http/Controllers/RasoioAvailabilityController.php',
        'app/Http/Controllers/RasoioDashboardController.php',
        'app/Http/Controllers/RasoioEmployerController.php',
        'app/Http/Controllers/RasoioWorkflowController.php',
        'app/Mail/CutinappTicketsMail.php',
        'app/Models/CutinappArtist.php',
        'app/Models/CutinappArtistClaim.php',
        'app/Models/CutinappArtistMember.php',
        'app/Models/CutinappEventItem.php',
        'app/Models/CutinappOrder.php',
        'app/Models/CutinappOrderItem.php',
        'app/Models/CutinappPayment.php',
        'app/Services/CutinappEventAudienceService.php',
        'app/Services/CutinappLineupNotificationService.php',
        'app/Services/CutinappLocationService.php',
        'app/Services/CutinappProducerContractService.php',
    ];

    public function test_domain_layer_never_mentions_product_names(): void
    {
        $domainPath = app_path('Domain');
        if (! File::isDirectory($domainPath)) {
            $this->markTestSkipped('Domain layer has not been created.');
        }

        $violations = [];
        foreach (File::allFiles($domainPath) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) continue;
            $contents = File::get($file->getPathname());
            if (preg_match('/\b(Cutinapp|Rasoio|Nexus|Plat|Laora|Payflow|PayFlow)\b/i', $contents)) {
                $violations[] = $this->relative($file->getPathname());
            }
        }

        $this->assertSame([], $violations, 'Domain code cannot depend on Peter product names: '.implode(', ', $violations));
    }

    public function test_no_new_product_prefixed_core_classes_are_added(): void
    {
        $roots = [
            app_path('Http/Controllers'),
            app_path('Services'),
            app_path('Models'),
            app_path('Console/Commands'),
            app_path('Mail'),
        ];

        $found = [];
        foreach ($roots as $root) {
            if (! File::isDirectory($root)) continue;
            foreach (File::allFiles($root) as $file) {
                if (! preg_match(self::PRODUCT_PREFIX, $file->getFilename())) continue;
                $found[] = $this->relative($file->getPathname());
            }
        }

        sort($found);
        $allowed = self::LEGACY_CLASS_ALLOWLIST;
        sort($allowed);

        $unexpected = array_values(array_diff($found, $allowed));
        $this->assertSame([], $unexpected, 'New product-specific core classes are forbidden: '.implode(', ', $unexpected));
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
