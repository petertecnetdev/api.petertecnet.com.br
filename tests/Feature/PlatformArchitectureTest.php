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

                if (preg_match($sourcePattern, $contents)) {
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

    public function test_routes_are_application_scoped_and_never_product_prefixed(): void
    {
        $violations = [];
        $routesPath = base_path('routes');

        foreach (File::files($routesPath) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) continue;

            $relative = $this->relative($file->getPathname());
            $contents = File::get($file->getPathname());

            if (preg_match($this->filenameProductPattern(), $file->getFilename())) {
                $violations[] = $relative.' [filename]';
            }

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
            'Product-prefixed route contracts are forbidden. Use /api/v1/apps/{application}/<capability>: '.implode(', ', $violations)
        );
    }

    public function test_runtime_code_never_references_product_prefixed_operational_tables(): void
    {
        $roots = [app_path(), base_path('routes')];
        $violations = [];
        $tablePrefixPattern = '/\b('.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_[a-z0-9_]+\b/i';

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) continue;

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) continue;

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
            'Final schema still contains application-prefixed operational tables: '.implode(', ', $violations)
        );
    }

    private function filenameProductPattern(): string
    {
        // Plat is special because "Platform" is a legitimate architecture term.
        return '/^(Cutinapp|Rasoio|Nexus|Laora|Payflow|Inkap|CamQuick|Plat(?!form))/i';
    }

    private function sourceProductPattern(): string
    {
        // Catch both standalone slugs/branding literals and CamelCase classes,
        // while explicitly avoiding the legitimate word "Platform".
        return '/(?<![A-Za-z0-9_])(Cutinapp|Rasoio|Nexus|Laora|Payflow|Inkap|CamQuick|Plat(?!form))/i';
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
