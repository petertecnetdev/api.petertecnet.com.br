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
        $this->assertSame('#d01312', $brand['primary_color']);
        $this->assertSame('#2d2d2d', $brand['secondary_color']);
        $this->assertSame('#f0564e', $brand['accent_color']);
        $this->assertSame('#080808', $brand['header_background_color']);
        $this->assertSame('#f4f4f4', $brand['page_background_color']);
        $this->assertFalse($brand['is_factory']);
    }

    public function test_email_brand_colors_override_generic_published_branding(): void
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
        $this->assertSame('#d01312', $brand['primary_color']);
        $this->assertSame('#2d2d2d', $brand['secondary_color']);
        $this->assertSame('#f0564e', $brand['accent_color']);
    }

    public function test_published_branding_remains_color_fallback_without_email_overrides(): void
    {
        $application = new Application([
            'name' => 'Nexus',
            'slug' => 'nexus',
            'url' => 'https://nexus.petertecnet.com.br',
            'branding' => [
                'primary_color' => '#112233',
                'secondary_color' => '#445566',
                'accent_color' => '#778899',
            ],
        ]);

        $brand = app(ApplicationMailBrandingService::class)->forApplication($application);

        $this->assertSame('#112233', $brand['primary_color']);
        $this->assertSame('#445566', $brand['secondary_color']);
        $this->assertSame('#778899', $brand['accent_color']);
    }
}
