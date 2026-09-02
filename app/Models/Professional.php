<?php

namespace App\Models;

/**
 * Canonical professional/person-to-organization role model.
 * The existing employers table is retained as the compatibility storage layer.
 */
class Professional extends Employer
{
    protected $table = 'employers';
}
