<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EcosystemProductIsolationTest extends TestCase
{
    private const PRODUCT_NAMES = [
        'cutinapp', 'rasoio', 'nexus', 'plat', 'laora', 'payflow', 'inkap',
        'camquick', 'locaio', 'kryvion',
    ];

    public function test_product_names_do_not_leak_into_generic_runtime_or_new_storage(): void
    {
        $violations = [];
        $namePattern = '/(?<![A-Za-z0-9_])('.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')(?![A-Za-z0-9_])/i';
        $storagePattern = '/\\b(?:Schema::(?:create|table)|DB::table)\\s*\\(\\s*[\'\"](?:'.implode('|', array_map('preg_quote', self::PRODUCT_NAMES)).')_/i';

        foreach ([app_path('Domain'), app_path('Models'), app_path('Services')] as $root) {
            if (! File::isDirectory($root)) continue;

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) continue;
                if (preg_match($namePattern, File::get($file->getPathname()))) {
                    $violations[] = $this->relative($file->getPathname()).' [runtime]';
                }
            }
        }

        foreach (File::files(database_path('migrations')) as $file) {
            if (preg_match($storagePattern, File::get($file->getPathname()))) {
                $violations[] = $this->relative($file->getPathname()).' [storage]';
            }
        }

        sort($violations);
        $this->assertSame([], $violations,
            "Product-specific architecture is forbidden. Select behavior through application context/capabilities instead.\n".
            implode("\n", $violations)
        );
    }

    public function test_every_current_product_is_covered_by_the_primary_architecture_contract(): void
    {
        $contract = File::get(base_path('tests/Feature/PlatformArchitectureTest.php'));

        foreach (self::PRODUCT_NAMES as $product) {
            $this->assertStringContainsString(
                "'{$product}'",
                strtolower($contract),
                "PlatformArchitectureTest must explicitly protect {$product}."
            );
        }
    }

    private function relative(string $path): string
    {
        return str_replace('\\\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
