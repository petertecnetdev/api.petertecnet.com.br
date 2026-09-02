<?php

namespace App\Models;

/**
 * Canonical artist model for the Events/People domain.
 * The legacy table name is preserved during the zero-downtime migration.
 */
class Artist extends CutinappArtist
{
    protected $table = 'cutinapp_artists';
}
