<?php

namespace Tests\Unit;

use App\Domain\Discovery\Services\SearchQueryParser;
use App\Domain\Discovery\Services\SearchRelevance;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DiscoverySearchTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parser_normalizes_accents_hashtags_and_natural_language_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 11:00:00', 'America/Sao_Paulo'));

        $parsed = app(SearchQueryParser::class)->parse([
            'q' => '#Sertanejo grátis em Goiânia amanhã até 50 10km',
        ]);

        $this->assertSame('sertanejo gratis em goiania amanha ate 50 10km', $parsed['normalized']);
        $this->assertContains('sertanejo', $parsed['hashtags']);
        $this->assertTrue($parsed['filters']['free']);
        $this->assertSame('tomorrow', $parsed['filters']['period']);
        $this->assertSame(50.0, $parsed['filters']['max_price']);
        $this->assertSame(10, $parsed['filters']['radius_km']);
        $this->assertSame('Goiânia', $parsed['filters']['city']);
    }

    public function test_parser_understands_weekdays_and_formats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-11 11:00:00', 'America/Sao_Paulo'));

        $parsed = app(SearchQueryParser::class)->parse([
            'q' => 'eletrônica sábado presencial',
        ]);

        $this->assertSame('in_person', $parsed['filters']['format']);
        $this->assertSame('2026-09-12', $parsed['filters']['date_from']);
        $this->assertSame('2026-09-12', $parsed['filters']['date_to']);
        $this->assertContains('eletronica', $parsed['expanded_terms']);
        $this->assertContains('edm', $parsed['expanded_terms']);
    }

    public function test_relevance_is_accent_insensitive_and_tolerates_typos(): void
    {
        $relevance = new SearchRelevance();

        $exact = $relevance->score('joao', 'João');
        $typo = $relevance->score('marco roge', 'Marco Roger');
        $unrelated = $relevance->score('marco roge', 'Festival Sertanejo');

        $this->assertGreaterThan(1000, $exact);
        $this->assertGreaterThan($unrelated, $typo);
        $this->assertGreaterThan(100, $typo);
    }

    public function test_relevance_boosts_real_business_signals_without_overpowering_text(): void
    {
        $relevance = new SearchRelevance();

        $base = $relevance->score('sexta', 'Sextou');
        $withSignals = $relevance->score('sexta', 'Sextou', null, [], [
            'sales' => 120,
            'followers' => 900,
            'upcoming' => true,
            'distance_km' => 2.5,
        ]);
        $spam = $relevance->score('sexta', 'Evento sem relação', null, [], [
            'sales' => 10000000,
            'followers' => 10000000,
        ]);

        $this->assertGreaterThan($base, $withSignals);
        $this->assertGreaterThan($spam, $withSignals);
    }
}
