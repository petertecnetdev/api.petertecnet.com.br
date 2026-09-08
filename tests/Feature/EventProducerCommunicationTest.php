<?php

namespace Tests\Feature;

use App\Mail\EventProducerUpdatedMail;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Services\EventProducerCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventProducerCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_owner_receives_in_app_notification_and_email_for_event_change(): void
    {
        Mail::fake();

        $application = Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);

        $owner = User::create([
            'first_name' => 'Produtor',
            'email' => 'producer-notification@example.test',
            'user_name' => 'producer-notification',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Produção Comunicação',
            'slug' => 'producao-comunicacao',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $event = Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Evento Comunicação',
            'description' => 'Evento usado para validar a comunicação com o produtor.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/evento',
            'start_date' => now()->addDay(),
            'end_date' => now()->addDay()->addHours(2),
            'slug' => 'evento-comunicacao',
            'is_published' => false,
            'is_cancelled' => false,
        ]);

        app(EventProducerCommunicationService::class)->notify(
            $event,
            'updated',
            ['title', 'start_date']
        );

        $notification = AppNotification::query()
            ->where('app_id', $application->id)
            ->where('user_id', $owner->id)
            ->where('reference_type', 'event')
            ->where('reference_id', $event->id)
            ->where('type', 'producer_event_updated')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('/event/edit/'.$event->id, $notification->reference_url);
        $this->assertSame(['title', 'start_date'], $notification->data['changed_fields']);
        $this->assertSame('https://cutinapp.example.test', $notification->data['app_url']);
        $this->assertSame('https://cutinapp.example.test/event/evento-comunicacao', $notification->data['event_url']);
        $this->assertSame('https://cutinapp.example.test/event/edit/'.$event->id, $notification->data['event_management_url']);

        Mail::assertSent(EventProducerUpdatedMail::class, function (EventProducerUpdatedMail $mail) use ($owner, $event) {
            return $mail->hasTo($owner->email)
                && $mail->event->is($event)
                && $mail->action === 'updated'
                && $mail->appName === 'Cutinapp'
                && $mail->appUrl === 'https://cutinapp.example.test'
                && $mail->eventUrl === 'https://cutinapp.example.test/event/evento-comunicacao'
                && $mail->eventManagementUrl === 'https://cutinapp.example.test/event/edit/'.$event->id
                && str_contains($mail->notificationTitle, 'Evento atualizado');
        });
    }
}
