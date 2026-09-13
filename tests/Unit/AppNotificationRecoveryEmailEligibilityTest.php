<?php

namespace Tests\Unit;

use App\Models\AppNotification;
use App\Models\Application;
use App\Services\AppNotificationService;
use ReflectionMethod;
use Tests\TestCase;

final class AppNotificationRecoveryEmailEligibilityTest extends TestCase
{
    public function test_checkout_recovery_email_is_allowed_for_plat_without_enabling_general_plat_emails(): void
    {
        $application = new Application([
            'name' => 'Plat',
            'slug' => 'plat',
            'url' => 'https://plat.petertecnet.com.br',
        ]);

        self::assertTrue($this->shouldEmail($application, new AppNotification([
            'type' => 'checkout_recovery',
        ])));

        self::assertFalse($this->shouldEmail($application, new AppNotification([
            'type' => 'general',
        ])));

        self::assertFalse($this->shouldEmail($application, new AppNotification([
            'type' => 'subscription_checkout_recovery',
        ])));
    }

    public function test_existing_cutinapp_email_behavior_is_preserved(): void
    {
        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
        ]);

        self::assertTrue($this->shouldEmail($application, new AppNotification([
            'type' => 'general',
        ])));
    }

    private function shouldEmail(Application $application, AppNotification $notification): bool
    {
        $service = app(AppNotificationService::class);
        $method = new ReflectionMethod($service, 'shouldEmailNotification');

        return (bool) $method->invoke($service, $application, $notification);
    }
}
