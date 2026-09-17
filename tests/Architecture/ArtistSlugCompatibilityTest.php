<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArtistSlugCompatibilityTest extends TestCase
{
    public function test_artist_slug_generation_preserves_soft_deleted_and_legacy_fallback_contracts(): void
    {
        $source = file_get_contents(base_path('app/Domain/People/Http/Controllers/ArtistClaimController.php'));

        $this->assertIsString($source);

        $methodStart = strpos($source, 'private function uniqueArtistSlug(');
        $methodEnd = strpos($source, "\n    }", $methodStart ?: 0);

        $this->assertNotFalse($methodStart, 'Artist slug generator was not found.');
        $this->assertNotFalse($methodEnd, 'Artist slug generator boundary was not found.');

        $method = substr($source, $methodStart, $methodEnd - $methodStart);

        $this->assertStringContainsString(
            "Str::slug($stageName) ?: 'artista'",
            $method,
            'The historical empty-slug fallback is a compatibility contract and must remain artista.',
        );
        $this->assertStringContainsString(
            'Artist::withTrashed()',
            $method,
            'Soft-deleted artists must keep reserving their slug so restore cannot collide.',
        );
        $this->assertStringContainsString(
            "->where('app_id', $this->context->id())",
            $method,
            'Slug uniqueness must remain scoped to the active application.',
        );
    }
}
