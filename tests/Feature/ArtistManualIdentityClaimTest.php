<?php

namespace Tests\Feature;

use App\Domain\People\Services\ArtistOnboardingService;
use App\Models\Application;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistManualIdentityClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_professional_email_requires_human_review(): void
    {
        $application = Application::query()->firstOrCreate(
            ['slug' => 'cutinapp'],
            ['name' => 'Cutinapp', 'is_active' => true],
        );

        $applicant = User::query()->create([
            'first_name' => 'Artista',
            'last_name' => 'Solicitante',
            'email' => 'artista-test@example.com',
            'email_verified_at' => now(),
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        ]);

        $artist = Artist::query()->create([
            'app_id' => $application->id,
            'artist_type' => 'solo',
            'stage_name' => 'Artista Sem Titular',
            'slug' => 'artista-sem-titular-teste',
            'professional_email' => $applicant->email,
            'verification_status' => 'unverified',
            'is_active' => true,
            'is_published' => true,
        ]);

        $response = app(ArtistOnboardingService::class)->claimExisting(
            $application->id,
            $applicant,
            $artist->id,
            ['evidence_text' => 'Solicito a revisão de minha identidade.'],
        );

        $this->assertSame('pending', $response['status']);
        $this->assertNull($artist->fresh()->user_id);
        $this->assertNull($artist->fresh()->claimed_at);
        $this->assertDatabaseHas('artist_identity_claims', [
            'app_id' => $application->id,
            'artist_id' => $artist->id,
            'user_id' => $applicant->id,
            'status' => 'pending',
            'reviewed_by_user_id' => null,
        ]);
    }
}
