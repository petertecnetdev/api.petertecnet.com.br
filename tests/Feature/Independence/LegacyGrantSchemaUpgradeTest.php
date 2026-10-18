<?php

declare(strict_types=1);

namespace Tests\Feature\Independence;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class LegacyGrantSchemaUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    private function prepareLegacyTable(bool $unique = true): void
    {
        Schema::dropIfExists('independence_entity_grants');

        Schema::create('independence_entity_grants', function (Blueprint $table) use ($unique): void {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->string('entity_type', 24);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('approved_claim_id');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            if ($unique) {
                $table->unique(['app_id', 'entity_type', 'entity_id'], 'independence_one_owner_per_entity');
            }
        });
    }

    private function grant(int $entityId, int $userId, ?string $revokedAt = null): array
    {
        return [
            'app_id' => 1,
            'entity_type' => 'production',
            'entity_id' => $entityId,
            'user_id' => $userId,
            'approved_claim_id' => $userId,
            'approved_by_user_id' => 999,
            'revoked_at' => $revokedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function upgrade(): void
    {
        $migration = require database_path(
            'migrations/2026_10_18_030000_upgrade_legacy_independence_entity_grants.php'
        );

        $migration->up();
    }

    public function test_upgrade_keeps_audit_history_and_permits_a_new_grant_only_after_revocation(): void
    {
        $this->prepareLegacyTable();
        DB::table('independence_entity_grants')->insert([
            $this->grant(71, 100),
            $this->grant(72, 101, '2026-10-17 12:00:00'),
        ]);
        DB::table('independence_claim_audits')->insert([
            'app_id' => 1,
            'claim_id' => 100,
            'actor_user_id' => 999,
            'previous_status' => 'pending',
            'decision' => 'approved',
            'review_note' => 'Comprovação verificada.',
            'created_at' => now(),
        ]);

        $this->upgrade();
        $this->upgrade(); // Re-running a partial or completed upgrade is safe.

        $this->assertTrue(Schema::hasIndex('independence_entity_grants', 'independence_one_active_owner'));
        $this->assertFalse(Schema::hasIndex('independence_entity_grants', 'independence_one_owner_per_entity'));
        $this->assertDatabaseHas('independence_entity_grants', [
            'user_id' => 100, 'entity_id' => 71, 'active_entity_key' => '1:production:71',
        ]);
        $this->assertDatabaseHas('independence_entity_grants', [
            'user_id' => 101, 'entity_id' => 72, 'active_entity_key' => null,
        ]);
        $this->assertDatabaseHas('independence_claim_audits', [
            'claim_id' => 100, 'decision' => 'approved',
        ]);

        try {
            DB::table('independence_entity_grants')->insert(
                $this->grant(71, 102) + ['active_entity_key' => '1:production:71']
            );
            $this->fail('O índice deve impedir duas concessões ativas da mesma entidade.');
        } catch (QueryException) {
            // Expected: uniqueness is enforced in the database, not just the service.
        }

        DB::table('independence_entity_grants')->where('user_id', 100)
            ->update(['revoked_at' => now(), 'active_entity_key' => null]);
        DB::table('independence_entity_grants')->insert(
            $this->grant(71, 102) + ['active_entity_key' => '1:production:71']
        );

        $this->assertDatabaseCount('independence_entity_grants', 3);
        $this->assertDatabaseHas('independence_entity_grants', [
            'user_id' => 100, 'entity_id' => 71, 'active_entity_key' => null,
        ]);
        $this->assertDatabaseHas('independence_entity_grants', [
            'user_id' => 102, 'entity_id' => 71, 'active_entity_key' => '1:production:71',
        ]);
    }

    public function test_duplicate_active_owners_fail_closed_before_changing_schema(): void
    {
        $this->prepareLegacyTable(false);
        DB::table('independence_entity_grants')->insert([
            $this->grant(71, 100),
            $this->grant(71, 102),
        ]);

        try {
            $this->upgrade();
            $this->fail('Dados contraditórios não podem receber migração automática.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicadas', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('independence_entity_grants', 'active_entity_key'));
        $this->assertDatabaseCount('independence_entity_grants', 2);
    }
}
