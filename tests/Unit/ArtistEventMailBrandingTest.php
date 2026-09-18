<?php

namespace Tests\Unit;

use App\Mail\ArtistEventInvitationMail;
use App\Mail\ArtistEventStatusMail;
use App\Models\Application;
use Tests\TestCase;

class ArtistEventMailBrandingTest extends TestCase
{
    public function test_artist_invitation_uses_cutinapp_branding_with_centralized_gmail_sender(): void
    {
        config()->set('mail.from.address', 'petertecnet@gmail.com');
        config()->set('mail.from.name', 'Peter Tecnet');

        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
            'logo' => 'https://cutinapp.petertecnet.com.br/images/logo.png',
        ]);

        $mail = new ArtistEventInvitationMail(
            'Sexta na La Fyesta',
            'La Fyesta',
            'https://cutinapp.petertecnet.com.br/artist/invitations/token123',
            'DJ set',
            '2026-09-19 22:00:00',
            'Palco principal',
            [
                'event_image' => 'https://cutinapp.petertecnet.com.br/storage/events/flyer.jpg',
                'start_date' => '2026-09-19T22:00:00-03:00',
                'venue' => 'La Fyesta',
                'formatted_address' => 'Goiânia - GO',
                'fee_cents' => 150000,
            ],
            $application,
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Cutinapp', $html);
        $this->assertStringContainsString('https://cutinapp.petertecnet.com.br/images/logo.png', $html);
        $this->assertStringContainsString('#b847fa', strtolower($html));
        $this->assertStringContainsString('Sexta na La Fyesta', $html);
        $this->assertStringContainsString('Ver convite e responder', $html);
        $this->assertStringContainsString('R$ 1.500,00', $html);
        $this->assertSame('petertecnet@gmail.com', $mail->from[0]['address'] ?? null);
        $this->assertSame('Cutinapp', $mail->from[0]['name'] ?? null);
    }

    public function test_artist_status_email_uses_same_application_identity(): void
    {
        config()->set('mail.from.address', 'petertecnet@gmail.com');

        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
            'logo' => 'https://cutinapp.petertecnet.com.br/images/logo.png',
        ]);

        $mail = new ArtistEventStatusMail(
            'Convite artístico cancelado',
            'Seu convite foi cancelado',
            'A produção cancelou o convite para este evento.',
            'Sexta na La Fyesta',
            '19/09/2026 22:00',
            'La Fyesta',
            $application,
        );

        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('Cutinapp', $html);
        $this->assertStringContainsString('https://cutinapp.petertecnet.com.br/images/logo.png', $html);
        $this->assertStringContainsString('#b847fa', strtolower($html));
        $this->assertStringContainsString('Seu convite foi cancelado', $html);
        $this->assertStringContainsString('Sexta na La Fyesta', $html);
        $this->assertSame('petertecnet@gmail.com', $mail->from[0]['address'] ?? null);
        $this->assertSame('Cutinapp', $mail->from[0]['name'] ?? null);
    }
}
