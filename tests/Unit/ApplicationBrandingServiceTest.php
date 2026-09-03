<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Services\ApplicationBrandingService;
use PHPUnit\Framework\TestCase;

class ApplicationBrandingServiceTest extends TestCase
{
    public function test_published_branding_keeps_legacy_logo_as_safe_fallback(): void
    {
        $application = new Application([
            'name' => 'Nexus',
            'description' => 'Catálogos digitais',
            'url' => 'https://nexus.petertecnet.com.br',
            'logo' => 'https://cdn.example.com/nexus-old.png',
        ]);
        $application->branding = [
            'primary_color' => '#112233',
        ];
        $application->branding_version = 4;

        $payload = (new ApplicationBrandingService())->publicPayload($application);

        $this->assertSame('Nexus', $payload['display_name']);
        $this->assertSame('https://cdn.example.com/nexus-old.png', $payload['logo']);
        $this->assertSame('https://cdn.example.com/nexus-old.png', $payload['logo_dark']);
        $this->assertSame('#112233', $payload['primary_color']);
        $this->assertSame(4, $payload['version']);
    }

    public function test_branding_assets_override_legacy_values_consistently(): void
    {
        $application = new Application([
            'name' => 'Rasoio',
            'logo' => 'https://cdn.example.com/legacy.png',
        ]);
        $application->branding = [
            'display_name' => 'Rasoio',
            'logo' => 'https://cdn.example.com/new.png',
            'icon' => 'https://cdn.example.com/icon.png',
        ];

        $payload = (new ApplicationBrandingService())->published($application);

        $this->assertSame('https://cdn.example.com/new.png', $payload['logo']);
        $this->assertSame('https://cdn.example.com/new.png', $payload['logo_light']);
        $this->assertSame('https://cdn.example.com/icon.png', $payload['favicon']);
    }

    public function test_uploaded_assets_receive_application_based_filenames(): void
    {
        $application = new Application([
            'name' => 'Rasoio',
            'slug' => 'rasoio',
        ]);
        $service = new ApplicationBrandingService();

        $this->assertSame('rasoio-logo.png', $service->assetFilename($application, 'logo', 'png'));
        $this->assertSame('rasoio-logo-light.webp', $service->assetFilename($application, 'logo_light', 'webp'));
        $this->assertSame('rasoio-logo-dark.jpg', $service->assetFilename($application, 'logo_dark', 'jpg'));
        $this->assertSame('rasoio-icon.png', $service->assetFilename($application, 'icon', 'png'));
        $this->assertSame('rasoio-favicon.png', $service->assetFilename($application, 'favicon', 'png'));
        $this->assertSame('rasoio-social-image.png', $service->assetFilename($application, 'social_image', 'png'));
    }
}
