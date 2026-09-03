<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Establishment;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiV1BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_reusable_business_profile_metadata(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);
        $user = User::create([
            'first_name' => 'Owner',
            'email' => 'business-profile@example.test',
            'user_name' => 'business-profile-owner',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $application = Application::create([
            'name' => 'Commerce',
            'slug' => 'commerce',
            'is_active' => true,
        ]);
        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => 'Loja de teste',
            'slug' => 'loja-de-teste',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = auth('api')->login($user);

        $payload = [
            'business_profile' => [
                'opening_hours' => [
                    'monday' => ['open' => '08:00', 'close' => '18:00'],
                ],
                'payment_methods' => ['pix', 'credit_card'],
                'delivery_available' => true,
                'pickup_available' => true,
                'service_area' => 'Centro e bairros próximos',
                'accessibility' => true,
                'parking' => false,
            ],
        ];

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/apps/commerce/establishments/' . $establishmentId, $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $establishmentId);

        $raw = Establishment::query()->findOrFail($establishmentId)->getRawOriginal('business_profile');
        $stored = json_decode((string) $raw, true);

        $this->assertSame(['pix', 'credit_card'], $stored['payment_methods']);
        $this->assertTrue($stored['delivery_available']);
        $this->assertSame('08:00', $stored['opening_hours']['monday']['open']);
    }

    public function test_business_profile_rejects_invalid_payment_method_shape(): void
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'permissions' => [],
        ]);
        $user = User::create([
            'first_name' => 'Owner',
            'email' => 'business-profile-invalid@example.test',
            'user_name' => 'business-profile-invalid',
            'password' => Hash::make('Test1234!'),
            'profile_id' => $profile->id,
        ]);
        $application = Application::create([
            'name' => 'Commerce',
            'slug' => 'commerce',
            'is_active' => true,
        ]);
        $establishmentId = DB::table('establishments')->insertGetId([
            'app_id' => $application->id,
            'name' => 'Loja inválida',
            'slug' => 'loja-invalida',
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'is_published' => true,
            'is_approved' => true,
            'is_cancelled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = auth('api')->login($user);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/apps/commerce/establishments/' . $establishmentId, [
                'business_profile' => [
                    'payment_methods' => [['unsafe' => 'shape']],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_profile.payment_methods.0');
    }
}
