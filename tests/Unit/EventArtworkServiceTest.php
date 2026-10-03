<?php

namespace Tests\Unit;

use App\Domain\Events\Services\EventArtworkService;
use App\Jobs\GenerateEventArtwork;
use App\Models\Event;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class EventArtworkServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_requests_generation_once_when_event_has_no_image(): void
    {
        Bus::fake();
        Cache::flush();

        $event = $this->eventWithoutPersistence(null);
        $service = app(EventArtworkService::class);

        $this->assertTrue($service->requestGeneration($event));
        $this->assertFalse($service->requestGeneration($event));

        Bus::assertDispatched(GenerateEventArtwork::class, fn (GenerateEventArtwork $job) => (
            $job->eventId === 123 && $job->applicationId === 7
        ));
    }

    public function test_it_does_not_request_generation_when_event_already_has_image(): void
    {
        Bus::fake();
        Cache::flush();

        $event = $this->eventWithoutPersistence('images/apps/cutinapp/events/existing.webp');

        $this->assertFalse(app(EventArtworkService::class)->requestGeneration($event));
        Bus::assertNothingDispatched();
    }

    private function eventWithoutPersistence(?string $image): Event
    {
        $event = new Event();
        $event->exists = true;
        $event->id = 123;
        $event->app_id = 7;
        $event->image = $image;

        return $event;
    }
}
