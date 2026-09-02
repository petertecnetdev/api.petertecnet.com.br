<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RENAMES = [
        'payflow_contacts' => 'crm_contacts',
        'payflow_opportunities' => 'crm_opportunities',
        'payflow_proposals' => 'crm_proposals',
        'payflow_charges' => 'crm_charges',
        'payflow_agent_activities' => 'crm_agent_activities',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $legacy => $domain) {
            if (Schema::hasTable($legacy) && ! Schema::hasTable($domain)) {
                Schema::rename($legacy, $domain);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $legacy => $domain) {
            if (Schema::hasTable($domain) && ! Schema::hasTable($legacy)) {
                Schema::rename($domain, $legacy);
            }
        }
    }
};
