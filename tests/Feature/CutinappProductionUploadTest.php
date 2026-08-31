<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappProductionUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_can_be_created_with_logo_and_background_uploads(): void
    {
        Storage::fake('public');

        $user = User::create([
            'first_name' => 'Produtor',
            'email' => 'producer-upload@example.test',
            'user_name' => 'producer-upload',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($user),
            'X-Peter-App' => 'cutinapp',
            'Accept' => 'application/json',
        ];

        $response = $this->withHeaders($headers)
            ->post('/api/cutinapp/productions', [
                'name' => 'Produção com Imagens',
                'fantasy' => 'Produtora Visual',
                'city' => 'São Paulo',
                'uf' => 'SP',
                'logo' => UploadedFile::fake()->image('logo.png', 800, 800),
                'background' => UploadedFile::fake()->image('capa.jpg', 1920, 900),
            ])
            ->assertCreated()
            ->assertJsonPath('production.app_slug', 'cutinapp')
            ->assertJsonPath('production.user_id', $user->id);

        $logo = $response->json('production.logo');
        $background = $response->json('production.background');

        $this->assertIsString($logo);
        $this->assertIsString($background);
        $this->assertStringEndsWith('.webp', $logo);
        $this->assertStringEndsWith('.webp', $background);
        Storage::disk('public')->assertExists($logo);
        Storage::disk('public')->assertExists($background);
    }
}
