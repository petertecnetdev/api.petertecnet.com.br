<?php

namespace Tests\Unit;

use App\Models\AppNotification;
use App\Models\Application;
use App\Services\AppNotificationService;
use ReflectionMethod;
use Tests\TestCase;

final class AppNotificationRecoveryEmailEligibilityTest extends TestCase
{
    public function test_transactional_recovery_emails_are_allowed_without_enabling_general_ecosystem_emails(): void
    {
        $applications = [
            new Application([
                'name' => 'Plat',
                'slug' => 'plat',
                'url' => 'https://plat.petertecnet.com.br',
            ]),
            new Application([
                'name' => 'Rasoio',
                'slug' => 'rasoio',
                'url' => 'https://rasoio.petertecnet.com.br',
            ]),
        ];

        foreach ($applications as $application) {
            self::assertTrue($this->shouldEmail($application, new AppNotification([
                'type' => 'checkout_recovery',
            ])));

            self::assertTrue($this->shouldEmail($application, new AppNotification([
                'type' => 'subscription_checkout_recovery',
            ])));

            self::assertFalse($this->shouldEmail($application, new AppNotification([
                'type' => 'general',
            ])));
        }
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
