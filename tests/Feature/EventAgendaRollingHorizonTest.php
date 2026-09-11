<?php

namespace Tests\Feature;

use App\Domain\Events\Services\EventAgendaMaintenanceService;
use App\Models\Event;
use App\Models\EventItem;
use App\Models\EventSchedule;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EventAgendaRollingHorizonTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_weekday_can_use_a_different_event_and_generation_strategy(): void
    {
        $timezone = 'America/Sao_Paulo';
        Carbon::setTestNow(Carbon::create(2026, 9, 10, 12, 0, 0, $timezone));

        $application = $this->applicationFixture('cutinapp', [
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);

        $owner = User::create([
            'first_name' => 'Produtor Grade',
            'email' => 'agenda-independent-days@example.test',
            'user_name' => 'agenda-independent-days',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Produção Grade Independente',
            'slug' => 'producao-grade-independente',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $friday = Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Sexta Independente',
            'description' => 'Evento exclusivo de sexta-feira.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/sexta',
            'start_date' => '2026-09-11 22:00:00',
            'end_date' => '2026-09-12 02:00:00',
            'slug' => 'sexta-independente',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $saturday = Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Sábado Independente',
            'description' => 'Evento exclusivo de sábado.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/sabado',
            'start_date' => '2026-09-12 22:00:00',
            'end_date' => '2026-09-13 02:00:00',
            'slug' => 'sabado-independente',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $fridaySchedule = EventSchedule::create([
            'app_id' => $application->id,
            'production_id' => $production->id,
            'source_event_id' => $friday->id,
            'title' => $friday->title,
            'description' => $friday->description,
            'day_of_week' => 5,
            'start_time' => '22:00',
            'end_time' => '02:00',
            'event_format' => 'online',
            'online_url' => $friday->online_url,
            'generation_mode' => 'immediate',
            'generation_delay_days' => 1,
            'generation_weeks' => 2,
            'is_active' => true,
        ]);

        $saturdaySchedule = EventSchedule::create([
            'app_id' => $application->id,
            'production_id' => $production->id,
            'source_event_id' => $saturday->id,
            'title' => $saturday->title,
            'description' => $saturday->description,
            'day_of_week' => 6,
            'start_time' => '22:00',
            'end_time' => '02:00',
            'event_format' => 'online',
            'online_url' => $saturday->online_url,
            'generation_mode' => 'delayed',
            'generation_delay_days' => 2,
            'generation_weeks' => 3,
            'is_active' => true,
        ]);

        $maintenance = app(EventAgendaMaintenanceService::class);
        $initial = $maintenance->replenishProduction(
            $application->id,
            $production->id,
            1,
            $application->slug,
        );

        $this->assertSame(1, $initial['created_count']);
        $this->assertSame(2, Event::query()->where('event_schedule_id', $fridaySchedule->id)->count());
        $this->assertSame(1, Event::query()->where('event_schedule_id', $saturdaySchedule->id)->count());

        $this->assertSame(
            ['Sexta Independente'],
            Event::query()
                ->where('event_schedule_id', $fridaySchedule->id)
                ->pluck('title')
                ->unique()
                ->values()
                ->all(),
        );
        $this->assertSame(
            ['Sábado Independente'],
            Event::query()
                ->where('event_schedule_id', $saturdaySchedule->id)
                ->pluck('title')
                ->unique()
                ->values()
                ->all(),
        );

        Carbon::setTestNow(Carbon::create(2026, 9, 14, 0, 15, 0, $timezone));

        $delayed = $maintenance->replenishSchedule(
            $saturdaySchedule->fresh('sourceEvent'),
            null,
            $application->slug,
        );

        $this->assertFalse($delayed['waiting']);
        $this->assertSame(3, $delayed['created_count']);
        $this->assertSame(3, $delayed['generation_weeks']);
        $this->assertSame(2, $delayed['generation_delay_days']);
        $this->assertSame('delayed', $delayed['generation_mode']);

        $this->assertDatabaseHas('event_schedules', [
            'id' => $fridaySchedule->id,
            'source_event_id' => $friday->id,
            'day_of_week' => 5,
            'generation_mode' => 'immediate',
            'generation_weeks' => 2,
        ]);
        $this->assertDatabaseHas('event_schedules', [
            'id' => $saturdaySchedule->id,
            'source_event_id' => $saturday->id,
            'day_of_week' => 6,
            'generation_mode' => 'delayed',
            'generation_delay_days' => 2,
            'generation_weeks' => 3,
        ]);
    }

    public function test_delayed_generation_never_accepts_seven_days_because_it_would_recreate_on_the_event_weekday(): void
    {
        $this->assertSame(6, (new \ReflectionMethod(EventAgendaMaintenanceService::class, 'normalizeDelayDays'))
            ->invoke(app(EventAgendaMaintenanceService::class), 7));

        $this->assertSame(6, (new \ReflectionMethod(EventAgendaMaintenanceService::class, 'normalizeDelayDays'))
            ->invoke(app(EventAgendaMaintenanceService::class), 6));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_agenda_keeps_only_the_selected_rolling_horizon_and_replenishes_after_day_rollover(): void
    {
        $timezone = 'America/Sao_Paulo';
        Carbon::setTestNow(Carbon::create(2026, 9, 10, 12, 0, 0, $timezone));

        $application = $this->applicationFixture('cutinapp', [
            'url' => 'https://cutinapp.example.test',
            'is_active' => true,
        ]);

        $owner = User::create([
            'first_name' => 'Produtor Agenda',
            'email' => 'agenda-rolling@example.test',
            'user_name' => 'agenda-rolling',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $production = Production::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'user_id' => $owner->id,
            'name' => 'Produção Agenda Semanal',
            'slug' => 'producao-agenda-semanal',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        $source = Event::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'production_id' => $production->id,
            'title' => 'Sextou Fixo',
            'description' => 'Evento modelo usado toda sexta-feira.',
            'event_format' => 'online',
            'online_url' => 'https://example.test/sextou',
            'start_date' => '2026-09-11 22:00:00',
            'end_date' => '2026-09-12 02:00:00',
            'slug' => 'sextou-fixo',
            'is_published' => true,
            'is_cancelled' => false,
        ]);

        Ticket::create([
            'app_id' => $application->id,
            'app_slug' => $application->slug,
            'event_id' => $source->id,
            'name' => 'Entrada',
            'type' => 'paid',
            'price' => 20,
            'limit_date' => '2026-09-11 21:30:00',
            'sales_cutoff_mode' => 'fixed',
            'sales_cutoff_offset_minutes' => 30,
            'ticket_type' => 'standard',
            'quantity' => 100,
            'max_per_user' => 4,
            'description' => 'Ingresso principal',
        ]);

        EventItem::create([
            'app_id' => $application->id,
            'event_id' => $source->id,
            'source_item_id' => null,
            'name' => 'Combo',
            'description' => 'Combo do evento',
            'price' => 80,
            'quantity' => 20,
            'promotion_enabled' => true,
            'promotion_price' => 70,
            'is_active' => true,
        ]);

        $schedule = EventSchedule::create([
            'app_id' => $application->id,
            'production_id' => $production->id,
            'source_event_id' => $source->id,
            'title' => $source->title,
            'description' => $source->description,
            'day_of_week' => 5,
            'start_time' => '22:00',
            'end_time' => '02:00',
            'event_format' => 'online',
            'online_url' => $source->online_url,
            'is_active' => true,
        ]);

        $maintenance = app(EventAgendaMaintenanceService::class);

        $twoWeeks = $maintenance->replenishSchedule($schedule, 2, $application->slug);
        $this->assertSame(1, $twoWeeks['created_count']);
        $this->assertSame(1, $twoWeeks['existing_count']);
        $this->assertSame(['2026-09-11', '2026-09-18'], $twoWeeks['target_dates']);

        $this->assertSame(2, Event::query()->where('event_schedule_id', $schedule->id)->count());
        $secondWeek = Event::query()
            ->where('event_schedule_id', $schedule->id)
            ->whereDate('event_schedule_occurrence_date', '2026-09-18')
            ->firstOrFail();

        $this->assertTrue((bool) $secondWeek->is_published);
        $this->assertSame(1, Ticket::query()->where('event_id', $secondWeek->id)->count());
        $this->assertSame(4, (int) Ticket::query()->where('event_id', $secondWeek->id)->value('max_per_user'));
        $this->assertSame(1, EventItem::query()->where('event_id', $secondWeek->id)->count());

        $threeWeeks = $maintenance->replenishSchedule($schedule->fresh('sourceEvent'), 3, $application->slug);
        $this->assertSame(1, $threeWeeks['created_count']);
        $this->assertSame(3, Event::query()->where('event_schedule_id', $schedule->id)->count());

        $oneWeek = $maintenance->replenishSchedule($schedule->fresh('sourceEvent'), 1, $application->slug);
        $this->assertSame(2, $oneWeek['retired_count']);
        $this->assertSame(1, Event::query()->where('event_schedule_id', $schedule->id)->count());

        Carbon::setTestNow(Carbon::create(2026, 9, 12, 0, 15, 0, $timezone));

        $nextCycle = $maintenance->replenishSchedule($schedule->fresh('sourceEvent'), 1, $application->slug);
        $this->assertSame(['2026-09-18'], $nextCycle['target_dates']);
        $this->assertSame(1, $nextCycle['created_count']);

        $this->assertDatabaseHas('events', [
            'event_schedule_id' => $schedule->id,
            'event_schedule_occurrence_date' => '2026-09-18',
            'is_cancelled' => false,
        ]);
    }
}
