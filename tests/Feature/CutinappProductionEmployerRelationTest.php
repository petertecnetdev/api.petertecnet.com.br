<?php

namespace Tests\Feature;

use App\Models\Production;
use Tests\TestCase;

class CutinappProductionEmployerRelationTest extends TestCase
{
    public function test_production_employers_use_establishment_foreign_key(): void
    {
        $production = new Production();

        $this->assertSame(
            'establishment_id',
            $production->employers()->getForeignKeyName()
        );
    }
}
