<?php

namespace Tests\Unit;

use App\Domain\Events\Services\TicketSalesCutoffService;
use App\Models\Event;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketSalesCutoffServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('rules')]
    public function test_it_projects_relative_cutoff_rules_per_event(array $rule, string $expected): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $event = new Event([
            'title' => 'Evento teste',
            'start_date' => '2026-09-10 22:00:00',
            'end_date' => '2026-09-11 04:00:00',
        ]);

        $result = app(TicketSalesCutoffService::class)->cutoffForEvent($event, $rule);

        $this->assertSame($expected, $result['limit_date']->format('Y-m-d H:i:s'));
        $this->assertFalse($result['clamped_to_event_end']);
    }

    public static function rules(): array
    {
        return [
            'at event start' => [['mode' => 'at_start', 'offset_minutes' => 0], '2026-09-10 22:00:00'],
            'two hours before start' => [['mode' => 'before_start', 'offset_minutes' => 120], '2026-09-10 20:00:00'],
            'two hours after start' => [['mode' => 'after_start', 'offset_minutes' => 120], '2026-09-11 00:00:00'],
            'one hour before end' => [['mode' => 'before_end', 'offset_minutes' => 60], '2026-09-11 03:00:00'],
        ];
    }

    public function test_it_never_allows_the_cutoff_to_pass_the_event_end(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $event = new Event([
            'title' => 'Evento curto',
            'start_date' => '2026-09-10 22:00:00',
            'end_date' => '2026-09-10 23:00:00',
        ]);

        $result = app(TicketSalesCutoffService::class)->cutoffForEvent($event, [
            'mode' => 'after_start',
            'offset_minutes' => 120,
        ]);

        $this->assertSame('2026-09-10 23:00:00', $result['limit_date']->format('Y-m-d H:i:s'));
        $this->assertTrue($result['clamped_to_event_end']);
    }
}
