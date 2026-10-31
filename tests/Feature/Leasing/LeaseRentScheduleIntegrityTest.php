<?php

namespace Tests\Feature\Leasing;

use App\Domain\Leasing\Services\LeaseRentScheduleService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class LeaseRentScheduleIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('lease_charges');
        Schema::dropIfExists('leases');

        Schema::create('leases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('app_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('due_day');
            $table->decimal('rent_amount', 12, 2);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('lease_charges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('app_id');
            $table->unsignedBigInteger('lease_id');
            $table->string('type', 40)->default('rent');
            $table->string('description', 190);
            $table->date('reference_date')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lease_charges');
        Schema::dropIfExists('leases');

        parent::tearDown();
    }

    public function test_migration_refuses_legacy_duplicate_rent_months_without_changing_existing_rows(): void
    {
        $this->insertCharge(10, 20, 'rent', '2026-10-01', '2026-10-10');
        $this->insertCharge(10, 20, 'rent', '2026-10-15', '2026-10-31');

        $migration = require database_path('migrations/2026_10_31_045600_enforce_unique_lease_rent_schedule.php');

        try {
            $migration->up();
            self::fail('A migration deveria bloquear meses de aluguel duplicados.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cobranças de aluguel duplicadas', $exception->getMessage());
            self::assertStringContainsString('app_id=10', $exception->getMessage());
            self::assertStringContainsString('lease_id=20', $exception->getMessage());
        }

        self::assertFalse(Schema::hasColumn('lease_charges', 'rent_schedule_month'));
        self::assertSame(2, DB::table('lease_charges')->count());
    }

    public function test_database_unique_key_blocks_duplicate_rent_months_but_allows_other_charge_types(): void
    {
        $this->runIntegrityMigration();
        $this->insertCharge(10, 20, 'rent', '2026-10-01', '2026-10-10');

        try {
            $this->insertCharge(10, 20, 'rent', '2026-10-15', '2026-10-31');
            self::fail('O banco deveria rejeitar duas cobranças de aluguel na mesma competência.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('lease_charges')->where('type', 'rent')->count());
        }

        $this->insertCharge(10, 20, 'water', '2026-10-01', '2026-10-10');
        $this->insertCharge(10, 20, 'water', '2026-10-15', '2026-10-31');

        self::assertSame(3, DB::table('lease_charges')->count());
        self::assertSame('2026-10-01', DB::table('lease_charges')->where('type', 'rent')->value('rent_schedule_month'));
    }

    public function test_schedule_generation_is_repeatable_and_clamps_due_day_to_month_length(): void
    {
        $this->runIntegrityMigration();

        $leaseId = DB::table('leases')->insertGetId([
            'app_id' => 10,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-03-31',
            'due_day' => 31,
            'rent_amount' => 1500,
            'deposit_amount' => 500,
        ]);

        $service = new LeaseRentScheduleService();

        self::assertSame(4, $service->ensureForLease(10, $leaseId));
        self::assertSame(0, $service->ensureForLease(10, $leaseId));

        $charges = DB::table('lease_charges')
            ->where('app_id', 10)
            ->where('lease_id', $leaseId)
            ->orderBy('rent_schedule_month')
            ->get();

        $rentCharges = $charges->where('type', 'rent')->values();
        self::assertCount(3, $rentCharges);
        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            $rentCharges->pluck('due_date')->all(),
        );
        self::assertSame(1, $charges->where('type', 'deposit')->count());
        self::assertSame(4, $charges->count());
    }

    private function runIntegrityMigration(): void
    {
        $migration = require database_path('migrations/2026_10_31_045600_enforce_unique_lease_rent_schedule.php');
        $migration->up();
    }

    private function insertCharge(int $appId, int $leaseId, string $type, ?string $referenceDate, string $dueDate): void
    {
        DB::table('lease_charges')->insert([
            'public_id' => (string) Str::uuid(),
            'app_id' => $appId,
            'lease_id' => $leaseId,
            'type' => $type,
            'description' => ucfirst($type),
            'reference_date' => $referenceDate,
            'due_date' => $dueDate,
            'amount' => 100,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
