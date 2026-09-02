<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class FinancialIdentityInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_verified_identity_invalidates_existing_pix_destination(): void
    {
        $producer = User::create([
            'first_name' => 'Produtor',
            'last_name' => 'Original',
            'email' => 'identity-change@cutinapp.test',
            'user_name' => 'identity-change-test',
            'password' => Hash::make('Test1234!'),
            'email_verified_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . JWTAuth::fromUser($producer),
            'X-Peter-App' => 'cutinapp',
        ];

        $productionId = $this->withHeaders($headers)
            ->postJson('/api/cutinapp/productions', [
                'name' => 'Produção Identidade',
                'city' => 'São Paulo',
                'uf' => 'SP',
            ])
            ->assertCreated()
            ->json('production.id');

        $oldCpf = '52998224725';
        $beneficiaryId = DB::table('financial_beneficiaries')->insertGetId([
            'user_id' => $producer->id,
            'legal_name' => 'Produtor Original',
            'document_type' => 'CPF',
            'document_number' => Crypt::encryptString($oldCpf),
            'document_number_hash' => hash_hmac('sha256', $oldCpf, (string) config('app.key')),
            'birthdate' => '1990-01-01',
            'status' => 'verified',
            'verification_level' => 'document_face_liveness',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financial_payout_destinations')->insert([
            'beneficiary_id' => $beneficiaryId,
            'source_type' => 'production',
            'source_id' => $productionId,
            'provider' => 'asaas',
            'type' => 'pix',
            'pix_key_type' => 'CPF',
            'pix_key' => Crypt::encryptString($oldCpf),
            'pix_key_hash' => hash_hmac('sha256', 'CPF:' . $oldCpf, (string) config('app.key')),
            'pix_key_masked' => '***.982.247-**',
            'holder_name' => 'Produtor Original',
            'holder_document_masked' => '***.982.247-**',
            'status' => 'active',
            'verified_at' => now(),
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($headers)
            ->putJson("/api/finance/productions/{$productionId}/identity", [
                'legal_name' => 'Produtor Nome Atualizado',
                'document_type' => 'CPF',
                'document_number' => '11144477735',
                'birthdate' => '1991-02-03',
            ])
            ->assertOk()
            ->assertJsonPath('identity.beneficiary.status', 'pending')
            ->assertJsonPath('identity.ready_for_pix', false);

        $this->assertDatabaseHas('financial_beneficiaries', [
            'id' => $beneficiaryId,
            'status' => 'pending',
            'verification_level' => 'profile',
            'verified_at' => null,
        ]);

        $this->assertDatabaseHas('financial_payout_destinations', [
            'beneficiary_id' => $beneficiaryId,
            'source_type' => 'production',
            'source_id' => $productionId,
            'status' => 'identity_changed',
            'verified_at' => null,
            'cooling_until' => null,
        ]);

        $this->withHeaders($headers)
            ->getJson("/api/finance/productions/{$productionId}")
            ->assertOk()
            ->assertJsonPath('ready_for_sales', false)
            ->assertJsonPath('ready_for_payout', false);
    }
}
