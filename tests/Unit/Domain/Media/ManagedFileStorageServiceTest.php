<?php

namespace Tests\Unit\Domain\Media;

use App\Domain\Media\Services\ManagedFileStorageService;
use App\Domain\Media\Services\MediaContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class ManagedFileStorageServiceTest extends TestCase
{
    public function test_it_isolates_media_by_application_and_normalizes_context(): void
    {
        Storage::fake('local');
        $service = app(ManagedFileStorageService::class);

        $first = $service->storeForApplication(
            UploadedFile::fake()->image('avatar.jpg'),
            10,
            'Profile Avatars'
        );

        $second = $service->storeForApplication(
            UploadedFile::fake()->image('avatar.jpg'),
            20,
            'Profile Avatars'
        );

        $this->assertStringStartsWith('applications/10/profile-avatars/', $first['storage_path']);
        $this->assertStringStartsWith('applications/20/profile-avatars/', $second['storage_path']);
        $this->assertSame(10, $first['application_id']);
        $this->assertSame(20, $second['application_id']);
        $this->assertSame('profile-avatars', $first['context']);
        $this->assertNotSame($first['storage_path'], $second['storage_path']);
        Storage::disk('local')->assertExists($first['storage_path']);
        Storage::disk('local')->assertExists($second['storage_path']);
    }

    public function test_it_supports_shared_hierarchical_media_contexts(): void
    {
        Storage::fake('local');
        $service = app(ManagedFileStorageService::class);

        $stored = $service->storeForApplication(
            UploadedFile::fake()->image('banner.jpg'),
            10,
            MediaContext::EVENT_BANNER
        );

        $this->assertSame('event/banner', $stored['context']);
        $this->assertStringStartsWith('applications/10/event/banner/', $stored['storage_path']);
        Storage::disk('local')->assertExists($stored['storage_path']);
    }

    public function test_media_context_normalization_cannot_escape_application_namespace(): void
    {
        $context = app(MediaContext::class);

        $this->assertSame('event/banner', $context->normalize('/Event/../Banner/'));
        $this->assertContains(MediaContext::SUPPORT_ATTACHMENT, $context->known());
        $this->assertContains(MediaContext::MESSAGE_ATTACHMENT, $context->known());
    }

    public function test_it_rejects_invalid_application_context(): void
    {
        Storage::fake('local');
        $service = app(ManagedFileStorageService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->storeForApplication(
            UploadedFile::fake()->create('file.txt', 1, 'text/plain'),
            0,
            MediaContext::DOCUMENT
        );
    }
}
