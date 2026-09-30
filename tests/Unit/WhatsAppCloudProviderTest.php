<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppCloudProvider;
use Tests\TestCase;

class WhatsAppCloudProviderTest extends TestCase
{
    public function test_normalizes_only_explicit_international_numbers(): void
    {
        $provider = app(WhatsAppCloudProvider::class);

        $this->assertSame('+5562999999999', $provider->normalizeE164('+55 (62) 99999-9999'));
        $this->assertSame('+14155552671', $provider->normalizeE164('0014155552671'));
        $this->assertNull($provider->normalizeE164('62999999999'));
        $this->assertNull($provider->normalizeE164('+0123456789'));
    }
}
