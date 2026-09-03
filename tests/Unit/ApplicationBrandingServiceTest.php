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
}
