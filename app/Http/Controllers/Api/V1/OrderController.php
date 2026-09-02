<?php

namespace App\Http\Controllers\Api\V1;

/**
 * Canonical v1 commerce endpoint.
 *
 * PlatOrderController is retained as the zero-downtime implementation adapter
 * until its remaining internals are fully extracted into Domain\Commerce.
 */
class OrderController extends PlatOrderController
{
}
