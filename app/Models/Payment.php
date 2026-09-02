<?php

namespace App\Models;

/**
 * Canonical payment model for the Peter Platform.
 *
 * EcosystemPayment remains as a legacy-compatible alias while product-specific
 * payment tables are migrated through adapters.
 */
class Payment extends EcosystemPayment
{
    protected $table = 'ecosystem_payments';
}
