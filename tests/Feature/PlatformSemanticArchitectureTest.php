<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformSemanticArchitectureTest extends TestCase
{
    private const NEW_PRODUCT_NAMES = ['locaio'];

    public function test_new_application_names_cannot_enter_runtime_architecture(): void
    {
        $roots = [
            app_path('Domain'),
            app_path('Http/Controllers'),
            app_path('Models'),
            app_path('Services'),
            app_path('Console/Commands'),
            app_path('Mail'),
        ];
        $violations = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                foreach (self::NEW_PRODUCT_NAMES as $productName) {
                    if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($productName, '/') . '(?![A-Za-z0-9_])/i', $contents)) {
                        $violations[] = $this->relative($file->getPathname()) . ' [' . $productName . ']';
                    }
                    if (stripos($file->getFilename(), $productName) !== false) {
                        $violations[] = $this->relative($file->getPathname()) . ' [filename:' . $productName . ']';
                    }
                }
            }
        }

        sort($violations);
        $this->assertSame([], array_values(array_unique($violations)), 'Application names belong to registration/configuration/compatibility, never reusable runtime architecture.');
    }

    public function test_new_application_names_cannot_enter_physical_storage(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $prefixPattern = '/^(' . implode('|', array_map('preg_quote', self::NEW_PRODUCT_NAMES)) . ')_/i';
        $violations = [];

        foreach ($schema->getTableListing() as $table) {
            $tableName = is_object($table) ? (string) ($table->name ?? '') : (string) $table;
            if ($tableName === '') {
                continue;
            }

            if (preg_match($prefixPattern, $tableName)) {
                $violations[] = 'table:' . $tableName;
            }

            foreach ($schema->getColumnListing($tableName) as $column) {
                if (preg_match($prefixPattern, (string) $column)) {
                    $violations[] = 'column:' . $tableName . '.' . $column;
                }
            }
        }

        foreach (File::files(database_path('migrations')) as $file) {
            $contents = File::get($file->getPathname());
            foreach (self::NEW_PRODUCT_NAMES as $productName) {
                $storagePattern = '/\b(?:Schema::(?:create|table)|DB::table)\s*\(\s*[\'\"]' . preg_quote($productName, '/') . '_/i';
                $columnPattern = '/->(?:string|text|integer|unsignedBigInteger|foreignId|uuid|boolean|decimal|timestamp|dateTime|json)\s*\(\s*[\'\"]' . preg_quote($productName, '/') . '_/i';

                if (preg_match($storagePattern, $contents, $matches)) {
                    $violations[] = $file->getFilename() . ' [storage:' . $matches[0] . ']';
                }
                if (preg_match($columnPattern, $contents, $matches)) {
                    $violations[] = $file->getFilename() . ' [column:' . $matches[0] . ']';
                }
            }
        }

        sort($violations);
        $this->assertSame([], array_values(array_unique($violations)), 'New applications cannot introduce product-prefixed operational storage.');
    }

    public function test_leasing_cannot_own_payment_storage_or_provider_implementation(): void
    {
        $violations = [];
        $root = app_path('Domain/Leasing');
        $forbidden = [
            "DB::table('ecosystem_payments')",
            'EcosystemPayment::',
            'MercadoPagoService',
            "'mercadopago'",
            '"mercadopago"',
        ];

        foreach (File::allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $contents = File::get($file->getPathname());
            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $this->relative($file->getPathname()) . ' [' . $needle . ']';
                }
            }
        }

        sort($violations);
        $this->assertSame([], $violations, 'Leasing must consume Finance/Payments through a reusable service boundary.');
    }

    public function test_discovery_has_one_canonical_search_index_writer(): void
    {
        $allowedWriter = realpath(app_path('Domain/Discovery/Services/DiscoverySearchIndexService.php'));
        $violations = [];

        foreach (File::allFiles(app_path('Domain/Discovery')) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $contents = File::get($file->getPathname());
            if (! str_contains($contents, 'discovery_search_documents')) {
                continue;
            }

            $writesIndex = preg_match(
                "/DB::table\(['\"]discovery_search_documents['\"]\)[\\s\\S]{0,240}?->(?:insert|update|upsert|delete)\s*\(/",
                $contents
            ) === 1;

            if ($writesIndex && realpath($file->getPathname()) !== $allowedWriter) {
                $violations[] = $this->relative($file->getPathname());
            }
        }

        sort($violations);
        $this->assertSame([], $violations, 'Discovery search documents must have a single canonical writer.');
    }

    public function test_learning_service_cannot_reimplement_search_index_engine(): void
    {
        $contents = File::get(app_path('Domain/Discovery/Services/DiscoveryLearningService.php'));

        $this->assertStringNotContainsString('function rebuildIndex(', $contents);
        $this->assertStringNotContainsString('function rankedSearch(', $contents);
        $this->assertStringContainsString('DiscoverySearchIndexService', $contents);
    }

    public function test_public_discovery_depends_on_shared_catalog_visibility_policy(): void
    {
        $discovery = File::get(app_path('Domain/Discovery/Services/DiscoveryService.php'));
        $index = File::get(app_path('Domain/Discovery/Services/DiscoverySearchIndexService.php'));

        $this->assertStringContainsString('PublicCatalogQuery', $discovery);
        $this->assertStringContainsString('PublicCatalogQuery', $index);
        $this->assertStringNotContainsString("where('is_approved', true)", $discovery);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
