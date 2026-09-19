<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Production;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationMediaManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_manage_gallery_lifecycle(): void
    {
        Storage::fake('public');

        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Gallery Owner', 'gallery-owner@example.test');
        $production = $this->production($app, $owner);
        $headers = $this->headersFor($owner);

        $first = $this->withHeaders($headers)->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
            'photo' => UploadedFile::fake()->image('pista.jpg', 1800, 1200),
            'caption' => 'Pista principal',
        ])->assertCreated()
            ->assertJsonPath('media.caption', 'Pista principal')
            ->assertJsonPath('media.position', 0)
            ->assertJsonPath('limit', 40);

        $firstId = (int) $first->json('media.id');
        $this->assertNotEmpty($first->json('media.thumbnail_url'));
        $this->assertNotEmpty($first->json('media.original_url'));

        $second = $this->withHeaders($headers)->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
            'photo' => UploadedFile::fake()->image('bar.jpg', 1600, 1200),
            'caption' => 'Bar',
        ])->assertCreated()->assertJsonPath('media.position', 1);

        $secondId = (int) $second->json('media.id');

        $album = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media-albums', ['name' => 'Estrutura'])
            ->assertCreated()
            ->assertJsonPath('album.name', 'Estrutura');

        $albumId = (int) $album->json('album.id');

        $this->withHeaders($headers)
            ->patchJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media/'.$firstId, [
                'caption' => 'Pista principal iluminada',
                'alt_text' => 'Pista principal da produção',
                'album_id' => $albumId,
                'is_featured' => true,
                'focal_x' => 60,
                'focal_y' => 40,
            ])
            ->assertOk()
            ->assertJsonPath('media.caption', 'Pista principal iluminada')
            ->assertJsonPath('media.album_id', $albumId)
            ->assertJsonPath('media.is_featured', true)
            ->assertJsonPath('media.focal_x', 60)
            ->assertJsonPath('media.focal_y', 40);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media-order', [
                'media_ids' => [$secondId, $firstId],
            ])
            ->assertOk()
            ->assertJsonPath('media.0.id', $secondId)
            ->assertJsonPath('media.1.id', $firstId);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media-delete', [
                'media_ids' => [$firstId],
            ])
            ->assertOk()
            ->assertJsonPath('deleted_ids.0', $firstId);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/workspace')
            ->assertOk()
            ->assertJsonCount(1, 'media')
            ->assertJsonPath('media.0.id', $secondId);

        $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media-restore', [
                'media_ids' => [$firstId],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'media');

        $cover = $this->withHeaders($headers)
            ->postJson('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media/'.$secondId.'/cover')
            ->assertOk();

        $this->assertNotEmpty($cover->json('path'));
        $this->assertDatabaseHas('establishments', [
            'id' => $production->id,
            'background' => $cover->json('path'),
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/apps/cutinapp/organizations/public/'.$production->slug.'/experience')
            ->assertOk()
            ->assertJsonCount(2, 'media')
            ->assertJsonMissingPath('media.0.original_url')
            ->assertJsonPath('gallery.limit', 40)
            ->assertJsonPath('gallery.recommended_min', 4);
    }

    public function test_non_owner_cannot_manage_gallery(): void
    {
        Storage::fake('public');

        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Media Owner', 'media-owner@example.test');
        $other = $this->user('Other Person', 'media-other@example.test');
        $production = $this->production($app, $owner);

        $this->withHeaders($this->headersFor($other))
            ->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
                'photo' => UploadedFile::fake()->image('forbidden.jpg', 1200, 800),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('organization_media', 0);
    }

    public function test_duplicate_upload_warns_but_does_not_block_owner(): void
    {
        Storage::fake('public');

        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Duplicate Owner', 'duplicate-owner@example.test');
        $production = $this->production($app, $owner);
        $headers = $this->headersFor($owner);

        $binary = UploadedFile::fake()->image('same.jpg', 1200, 800)->getContent();

        $this->withHeaders($headers)->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
            'photo' => UploadedFile::fake()->createWithContent('same-a.jpg', $binary),
        ])->assertCreated();

        $this->withHeaders($headers)->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
            'photo' => UploadedFile::fake()->createWithContent('same-b.jpg', $binary),
        ])->assertCreated()
            ->assertJsonPath('warning', 'Esta imagem parece já existir na galeria.');

        $this->assertDatabaseCount('organization_media', 2);
    }

    public function test_authenticated_user_can_report_public_gallery_photo_without_duplicate_open_reports(): void
    {
        Storage::fake('public');

        $app = Application::query()->where('slug', 'cutinapp')->firstOrFail();
        $owner = $this->user('Report Owner', 'report-owner@example.test');
        $reporter = $this->user('Gallery Reporter', 'gallery-reporter@example.test');
        $production = $this->production($app, $owner);

        $upload = $this->withHeaders($this->headersFor($owner))
            ->post('/api/v1/apps/cutinapp/organizations/'.$production->id.'/media', [
                'photo' => UploadedFile::fake()->image('reported.jpg', 1400, 900),
                'caption' => 'Foto para análise',
            ])
            ->assertCreated();

        $mediaId = (int) $upload->json('media.id');
        $url = '/api/v1/apps/cutinapp/organizations/public/'.$production->slug.'/media/'.$mediaId.'/report';

        $first = $this->withHeaders($this->headersFor($reporter))
            ->postJson($url, [
                'reason' => 'privacy',
                'details' => 'A imagem expõe informação que deve ser analisada.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Denúncia enviada para análise.');

        $reportId = (int) $first->json('report_id');
        $this->assertGreaterThan(0, $reportId);
        $this->assertDatabaseHas('organization_media_reports', [
            'id' => $reportId,
            'app_id' => $app->id,
            'organization_id' => $production->id,
            'media_id' => $mediaId,
            'reporter_user_id' => $reporter->id,
            'reason' => 'privacy',
            'status' => 'open',
        ]);

        $this->withHeaders($this->headersFor($reporter))
            ->postJson($url, [
                'reason' => 'privacy',
                'details' => 'Tentativa duplicada.',
            ])
            ->assertOk()
            ->assertJsonPath('report_id', $reportId)
            ->assertJsonPath('message', 'Sua denúncia desta foto já está em análise.');

        $this->assertDatabaseCount('organization_media_reports', 1);
    }

    private function production(Application $app, User $owner): Production
    {
        return Production::create([
            'app_id' => $app->id,
            'app_slug' => $app->slug,
            'user_id' => $owner->id,
            'name' => 'Galeria Teste',
            'slug' => 'galeria-teste-'.substr(md5($owner->email), 0, 6),
            'type' => 'fixed',
            'is_published' => true,
            'is_cancelled' => false,
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'email' => $email,
            'user_name' => strtolower(str_replace(' ', '-', $name)).'-'.substr(md5($email), 0, 8),
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);
    }

    private function headersFor(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
            'Accept' => 'application/json',
        ];
    }
}
