<?php

namespace Tests\Feature;

use Tests\TestCase;

class ForecastRoutesTest extends TestCase
{
    public function test_public_forecast_routes_are_registered(): void
    {
        $this->assertNotNull(app('router')->getRoutes()->getByName('forecasts.health'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('forecasts.index'));
        $this->assertNotNull(app('router')->getRoutes()->getByName('forecasts.show'));
    }
}
