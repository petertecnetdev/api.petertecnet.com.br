<?php

namespace App\Http\Controllers;

use App\Domain\Locations\Http\Controllers\LocationController;

/**
 * @deprecated Compatibility adapter for legacy /cutinapp/locations routes.
 * New routes must point directly to the Locations domain controller.
 */
class CutinappLocationController extends LocationController
{
}
