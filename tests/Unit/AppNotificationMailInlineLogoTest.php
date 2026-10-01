<?php

namespace Tests\Unit;

use App\Mail\AppNotificationMail;
use App\Models\AppNotification;
use App\Models\Application;
use App\Models\User;
use Tests\TestCase;

class AppNotificationMailInlineLogoTest extends TestCase
{
    public function test_cutinapp_notification_embeds_logo_instead_of_relying_on_remote_image(): void
    {
        $application = new Application([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.petertecnet.com.br',
        ]);

        $recipient = new User([
            'first_name' => 'Peter',
            'last_name' => 'Tecnet',
            'email' => 'peter@example.test',
        ]);

        $notification = new AppNotification([
            'title' => 'Novo ingresso disponível',
            'message' => 'Um novo ingresso foi adicionado.',
            'data' => [],
        ]);

        $html = (new AppNotificationMail(
            $recipient,
            $notification,
            $application,
            'https://cutinapp.petertecnet.com.br/tickets'
        ))->render();

        $this->assertTrue(
            str_contains($html, 'src="cid:') || str_contains($html, 'src="data:image/png;base64,'),
            'The rendered email should contain an inline logo source.'
        );
        $this->assertStringNotContainsString(
            'src="https://cutinapp.petertecnet.com.br/images/logo.png"',
            $html
        );
        $this->assertStringContainsString('alt="Logo Cutinapp"', $html);
    }
}
