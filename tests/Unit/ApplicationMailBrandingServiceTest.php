<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Services\ApplicationMailBrandingService;
use Tests\TestCase;

class ApplicationMailBrandingServiceTest extends TestCase
{
    public function test_cutinapp_uses_its_own_email_identity_when_no_database_branding_exists(): void
    {
        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
        ]);

        $brand = app(ApplicationMailBrandingService::class)->forApplication($application);

        $this->assertSame('Cutinapp', $brand['name']);
        $this->assertSame('Cutinapp', $brand['sender_name']);
        $this->assertSame('https://cutinapp.petertecnet.com.br/images/logo.png', $brand['logo_url']);
        $this->assertSame('#b847fa', $brand['primary_color']);
        $this->assertSame('#2b62f4', $brand['secondary_color']);
        $this->assertSame('#06affa', $brand['accent_color']);
        $this->assertFalse($brand['is_factory']);
    }

    public function test_published_application_branding_overrides_email_fallback_colors_and_logo(): void
    {
        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
            'branding' => [
                'display_name' => 'Cutinapp Eventos',
                'logo' => 'https://cdn.example.test/cutinapp.png',
                'primary_color' => '#112233',
                'secondary_color' => '#445566',
                'accent_color' => '#778899',
            ],
        ]);

        $brand = app(ApplicationMailBrandingService::class)->forApplication($application);

        $this->assertSame('Cutinapp Eventos', $brand['name']);
        $this->assertSame('https://cdn.example.test/cutinapp.png', $brand['logo_url']);
        $this->assertSame('#112233', $brand['primary_color']);
        $this->assertSame('#445566', $brand['secondary_color']);
        $this->assertSame('#778899', $brand['accent_color']);
    }
}
