<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EcosystemProductIsolationTest extends TestCase
{
    private const LEGACY_BASELINE = '34457ab71d3a740121cbea1c99fff3e5a8ecda32';
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
                if (preg_match($namePattern, $this->withoutComments(File::get($file->getPathname())))) {
                    $relative = $this->relative($file->getPathname());
                    if (! $this->matchesLegacyBaseline($relative, File::get($file->getPathname()))) {
                        $violations[] = $relative.' [runtime]';
                    }
                }
            }
        }

        foreach (File::files(database_path('migrations')) as $file) {
            if (preg_match($storagePattern, File::get($file->getPathname()))) {
                $relative = $this->relative($file->getPathname());
                if (! $this->matchesLegacyBaseline($relative, File::get($file->getPathname()))) {
                    $violations[] = $relative.' [storage]';
                }
            }
        }

        sort($violations);
        $this->assertSame([], $violations,
            "Product-specific architecture is forbidden. Select behavior through application context/capabilities instead.\n".
            implode("\n", $violations)
        );
    }


    private function matchesLegacyBaseline(string $relative, string $contents): bool
    {
        $output = [];
        $exitCode = 0;
        exec('git show '.escapeshellarg(self::LEGACY_BASELINE.':'.$relative).' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && rtrim(implode("\n", $output)) === rtrim($contents);
    }

    private function withoutComments(string $contents): string
    {
        $source = '';
        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    private function relative(string $path): string
    {
        return str_replace('\\\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
