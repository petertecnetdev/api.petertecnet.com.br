<?php

namespace Tests\Unit;

use App\Services\FlyerDateAnalysisService;
use Tests\TestCase;

final class FlyerDateAnalysisServiceTest extends TestCase
{
    public function test_recurring_flyer_with_fixed_date_requires_review(): void
    {
        $result = app(FlyerDateAnalysisService::class)->classify([
            ['raw' => '27/09', 'iso_date' => null, 'weekday' => 0, 'has_day' => true, 'has_month' => true, 'has_year' => false],
        ], '', 'America/Sao_Paulo', true, 0, .94);
        $this->assertSame('mismatch', $result['status']);
    }

    public function test_recurring_flyer_with_only_matching_weekday_is_consistent(): void
    {
        $result = app(FlyerDateAnalysisService::class)->classify([
            ['raw' => 'Domingo', 'iso_date' => null, 'weekday' => 0, 'has_day' => false, 'has_month' => false, 'has_year' => false],
        ], '', 'America/Sao_Paulo', true, 0, .95);
        $this->assertSame('consistent', $result['status']);
    }

    public function test_ambiguous_date_never_auto_confirms(): void
    {
        $result = app(FlyerDateAnalysisService::class)->classify([
            ['raw' => '04/05', 'iso_date' => null, 'weekday' => null, 'has_day' => true, 'has_month' => true, 'has_year' => false],
        ], '2026-05-04T20:00:00-03:00', 'America/Sao_Paulo', false, null, .81, true);
        $this->assertSame('ambiguous', $result['status']);
    }

    public function test_flyer_without_date_is_safe_and_explicit(): void
    {
        $result = app(FlyerDateAnalysisService::class)->classify([], '2026-09-27T20:00:00-03:00', 'America/Sao_Paulo', false, null, .97);
        $this->assertSame('no_date', $result['status']);
    }

    public function test_timezone_is_used_when_comparing_the_calendar_date(): void
    {
        $result = app(FlyerDateAnalysisService::class)->classify([
            ['raw' => '27 September 2026', 'iso_date' => '2026-09-27', 'weekday' => 0, 'has_day' => true, 'has_month' => true, 'has_year' => true],
        ], '2026-09-28T01:00:00Z', 'America/Sao_Paulo', false, null, .99);
        $this->assertSame('consistent', $result['status']);
        $this->assertSame('2026-09-27', $result['expected_date']);
    }
}
