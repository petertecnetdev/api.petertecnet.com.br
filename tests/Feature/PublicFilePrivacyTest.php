<?php

namespace Tests\Feature;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFilePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_file_entity_list_hides_user_level_analytics(): void
    {
        File::create([
            'entity_id' => 9001,
            'entity_name' => 'privacy_test',
            'path' => 'external/privacy-test',
            'public_url' => 'https://example.test/privacy-test.png',
            'storage' => 'external',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => null,
            'updated_by' => null,
        ]);

        $response = $this->getJson('/api/file/entity?entity_id=9001&entity_name=privacy_test');

        $response->assertOk();

        $file = $response->json('files.0');
        $this->assertIsArray($file);
        $this->assertArrayHasKey('metrics', $file);
        $this->assertArrayNotHasKey('interaction_summary', $file);
        $this->assertArrayNotHasKey('created_by', $file);
        $this->assertArrayNotHasKey('updated_by', $file);
        $this->assertArrayNotHasKey('storage_path', $file);
        $this->assertArrayNotHasKey('content_hash', $file);
        $this->assertArrayNotHasKey('checksum', $file);
    }

    public function test_public_file_view_hides_user_level_analytics(): void
    {
        $file = File::create([
            'entity_id' => 9002,
            'entity_name' => 'privacy_test',
            'path' => 'external/privacy-view-test',
            'public_url' => 'https://example.test/privacy-view-test.png',
            'storage' => 'external',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => null,
            'updated_by' => null,
        ]);

        $response = $this->getJson('/api/file/' . $file->uuid . '/view');

        $response->assertOk();

        $payload = $response->json('file');
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('metrics', $payload);
        $this->assertArrayNotHasKey('interaction_summary', $payload);
        $this->assertArrayNotHasKey('created_by', $payload);
        $this->assertArrayNotHasKey('updated_by', $payload);
        $this->assertArrayNotHasKey('storage_path', $payload);
        $this->assertArrayNotHasKey('content_hash', $payload);
        $this->assertArrayNotHasKey('checksum', $payload);
    }
}
