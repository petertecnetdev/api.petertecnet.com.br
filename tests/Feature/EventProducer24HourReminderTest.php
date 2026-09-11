<?php

namespace Tests\Feature;

use App\Mail\EventProducerUpdatedMail;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventProducer24HourReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_producer_receives_one_email_when_event_enters_next_24_hours(): void
    {
        config()->set('platform.applications.cutinapp.capabilities', ['events']);

        $application = Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);

        $owner = User::create([
            'first_name' => 'Produtor',
            'email' => 'producer-24h@example.test',
            'user_name' => 'producer-24h',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Produção 24h',
            'slug' => 'producao-24h',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $event = Event::withoutEvents(fn () => Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Evento Amanhã',
            'description' => 'Evento usado para validar o lembrete ao produtor.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/evento',
            'start_date' => now()->addHours(23)->addMinutes(50),
            'end_date' => now()->addHours(27),
            'slug' => 'evento-amanha',
            'is_published' => true,
            'is_cancelled' => false,
        ]));

        Mail::fake();

        $this->artisan('platform:remind-events')
            ->assertExitCode(0);

        Mail::assertSent(EventProducerUpdatedMail::class, function (EventProducerUpdatedMail $mail) use ($owner, $event) {
            return $mail->hasTo($owner->email)
                && $mail->event->is($event)
                && $mail->action === 'reminder_24h'
                && str_contains($mail->notificationTitle, 'próximas 24 horas');
        });

        $notification = AppNotification::query()
            ->where('app_id', $application->id)
            ->where('user_id', $owner->id)
            ->where('type', 'producer_event_reminder_24h')
            ->where('reference_type', 'event')
            ->where('reference_id', $event->id)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(24, $notification->data['reminder_hours']);
        $this->assertNotEmpty($notification->data['producer_email_sent_at']);

        $this->artisan('platform:remind-events')
            ->assertExitCode(0);

        Mail::assertSent(EventProducerUpdatedMail::class, 1);
        $this->assertSame(
            1,
            AppNotification::query()
                ->where('type', 'producer_event_reminder_24h')
                ->where('reference_type', 'event')
                ->where('reference_id', $event->id)
                ->count()
        );
    }

    public function test_event_outside_24_hour_window_does_not_notify_producer(): void
    {
        config()->set('platform.applications.cutinapp.capabilities', ['events']);

        $application = Application::create([
            'name' => 'Cutinapp',
            'slug' => 'cutinapp',
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);

        $owner = User::create([
            'first_name' => 'Produtor',
            'email' => 'producer-later@example.test',
            'user_name' => 'producer-later',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Produção Futura',
            'slug' => 'producao-futura',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        Event::withoutEvents(fn () => Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Evento Depois',
            'description' => 'Evento fora da janela de vinte e quatro horas.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/evento-depois',
            'start_date' => now()->addHours(25),
            'end_date' => now()->addHours(28),
            'slug' => 'evento-depois',
            'is_published' => true,
            'is_cancelled' => false,
        ]));

        Mail::fake();

        $this->artisan('platform:remind-events')
            ->assertExitCode(0);

        Mail::assertNotSent(EventProducerUpdatedMail::class);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $owner->id,
            'type' => 'producer_event_reminder_24h',
        ]);
    }
}
