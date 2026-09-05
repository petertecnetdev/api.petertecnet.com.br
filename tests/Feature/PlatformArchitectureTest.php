<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PlatformArchitectureTest extends TestCase
{
    private const PRODUCT_NAMES = [
        'cutinapp',
        'rasoio',
        'nexus',
        'plat',
        'laora',
        'payflow',
        'inkap',
        'camquick',
    ];

    private const STORAGE_CONTRACT_MIGRATION = '2026_09_03_204500_contract_application_specific_storage_to_generic_tables.php';

    public function test_runtime_architecture_contains_no_product_specific_names(): void
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
        $sourcePattern = $this->sourceProductPattern();
        $filenamePattern = $this->filenameProductPattern();

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) continue;

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) continue;

                $relative = $this->relative($file->getPathname());
                $contents = File::get($file->getPathname());

                if (preg_match($filenamePattern, $file->getFilename())) {
                    $violations[] = $relative.' [filename]';
                    continue;
                }

                if (preg_match($sourcePattern, $this->withoutComments($contents))) {
                    $violations[] = $relative.' [source]';
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            'Application names are forbidden in runtime architecture. Put product differences in application context/configuration only: '.implode(', ', $violations)
        );
    }

    public function test_canonical_routes_are_application_scoped_and_never_product_prefixed(): void
    {
        $violations = [];
        $routesPath = base_path('routes');

        foreach (File::files($routesPath) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) continue;

            $relative = $this->relative($file->getPathname());

            if (preg_match($this->filenameProductPattern(), $file->getFilename())) {
                $violations[] = $relative.' [filename]';
                continue;
            }

            // Zero-downtime rollout keeps all old URL aliases in one neutral,
            // explicitly deprecated adapter. It is transitional infrastructure,
            // not canonical application architecture.
            if ($file->getFilename() === 'compatibility.php') {
                continue;
            }

            $contents = File::get($file->getPathname());
            foreach (self::PRODUCT_NAMES as $product) {
                $routePatterns = [
                    "Route::prefix('{$product}')",
                    "Route::prefix(\"{$product}\")",
                    "'/{$product}/",
                    "\"/{$product}/",
                ];

                foreach ($routePatterns as $needle) {
                    if (stripos($contents, $needle) !== false) {
                        $violations[] = $relative.' [route:'.$product.']';
                        break;
                    }
                }
            }
        }

        sort($violations);
        $violations = array_values(array_unique($violations));

        $this->assertSame(
            [],
            $violations,
            'Canonical product-prefixed route contracts are forbidden. Use /api/v1/apps/{application}/<capability>: '.implode(', ', $violations)
        );
    }

    public function test_legacy_route_aliases_are_isolated_in_single_compatibility_adapter(): void
    {
        $compatibility = base_path('routes/compatibility.php');

        $this->assertFileExists($compatibility);
        $contents = File::get($compatibility);
        $this->assertStringContainsString('compatibility.route', $contents);

        foreach (File::files(base_path('routes')) as $file) {
            if ($file->getFilename() === 'compatibility.php' || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            foreach (self::PRODUCT_NAMES as $product) {
                $this->assertStringNotContainsString(
                    "Route::prefix('{$product}')",
                    File::get($file->getPathname()),
                    'Legacy application routes must live only in routes/compatibility.php.'
                );
            }
        }
    }

    public function test_runtime_code_never_references_product_prefixed_operational_tables(): void
    {
        $roots = [app_path(), base_path('routes')];
        $violations = [];
        $tablePrefixPattern = '/\\b('.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_[a-z0-9_]+\\b/i';

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) continue;

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) continue;

                // Transitional URL aliases may mention product slugs, but may
                // never query product-prefixed storage directly.
                $contents = File::get($file->getPathname());
                if (preg_match($tablePrefixPattern, $contents, $matches)) {
                    $violations[] = $this->relative($file->getPathname()).' ['.$matches[0].']';
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            'Runtime code cannot reference application-prefixed operational tables: '.implode(', ', $violations)
        );
    }

    public function test_final_database_schema_has_no_product_prefixed_operational_tables(): void
    {
        $tables = DB::connection()->getSchemaBuilder()->getTableListing();
        $prefixPattern = '/^('.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_/i';

        $violations = array_values(array_filter(
            array_map(fn ($table) => is_object($table) ? (string) ($table->name ?? '') : (string) $table, $tables),
            fn (string $table) => $table !== '' && preg_match($prefixPattern, $table) === 1
        ));

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            'Final clean-database schema still contains application-prefixed operational tables: '.implode(', ', $violations)
        );
    }

    public function test_final_database_schema_has_no_product_prefixed_operational_columns(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $prefixPattern = '/^('.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_/i';
        $violations = [];

        foreach ($schema->getTableListing() as $table) {
            $tableName = is_object($table) ? (string) ($table->name ?? '') : (string) $table;
            if ($tableName === '') continue;

            foreach ($schema->getColumnListing($tableName) as $column) {
                if (preg_match($prefixPattern, (string) $column) === 1) {
                    $violations[] = $tableName.'.'.$column;
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            'Final clean-database schema still contains application-prefixed operational columns: '.implode(', ', $violations)
        );
    }

    public function test_future_migrations_cannot_reintroduce_product_prefixed_storage(): void
    {
        $files = collect(File::files(database_path('migrations')))
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();
        $contractSeen = false;
        $violations = [];
        $storagePattern = '/\\b(?:Schema::(?:create|table)|DB::table)\\s*\\(\\s*[\'\"](?:'.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_/i';
        $columnPattern = '/(?:->(?:string|text|integer|unsignedBigInteger|foreignId|uuid|boolean|decimal|timestamp|dateTime|json)\\s*\\(\\s*[\'\"](?:'.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_)/i';

        foreach ($files as $file) {
            if ($file->getFilename() === self::STORAGE_CONTRACT_MIGRATION) {
                $contractSeen = true;
                continue;
            }

            if (! $contractSeen) continue;

            $contents = File::get($file->getPathname());
            if (preg_match($storagePattern, $contents, $matches)) {
                $violations[] = $file->getFilename().' [storage:'.$matches[0].']';
            }
            if (preg_match($columnPattern, $contents, $matches)) {
                $violations[] = $file->getFilename().' [column:'.$matches[0].']';
            }
        }

        $this->assertTrue($contractSeen, 'Generic storage contract migration is missing.');
        sort($violations);

        $this->assertSame(
            [],
            $violations,
            'Migrations after the storage contract cannot reintroduce application-prefixed physical storage: '.implode(', ', $violations)
        );
    }

    private function filenameProductPattern(): string
    {
        // Plat is an application slug, but Platform/Plataforma are legitimate
        // generic architecture/product terms and must never be false positives.
        return '/^(Cutinapp|Rasoio|Nexus|Laora|Payflow|Inkap|CamQuick|Plat(?!form|aform))/i';
    }

    private function sourceProductPattern(): string
    {
        // Catch standalone slugs/branding literals and CamelCase classes while
        // allowing the generic words Platform and Plataforma.
        return '/(?<![A-Za-z0-9_])(Cutinapp|Rasoio|Nexus|Laora|Payflow|Inkap|CamQuick|Plat(?!form|aform))/i';
    }


    private function withoutComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $source = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    private function relative(string $path): string
    {
        return str_replace('\\\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
