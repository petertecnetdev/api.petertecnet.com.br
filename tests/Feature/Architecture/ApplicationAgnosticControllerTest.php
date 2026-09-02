<?php

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class ApplicationAgnosticControllerTest extends TestCase
{
    private const PRODUCT_NAMES = ['cutinapp', 'plat', 'rasoio', 'nexus', 'laora', 'inkap', 'payflow'];

    public function test_domain_controller_names_never_contain_product_names(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers')));
        $violations = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $name = strtolower($file->getBasename('.php'));
            foreach (self::PRODUCT_NAMES as $product) {
                if (str_contains($name, $product)) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, "Controllers específicos por produto ainda existem:\n" . implode("\n", $violations));
    }

    public function test_canonical_v1_route_file_does_not_reference_product_controllers(): void
    {
        $routes = strtolower((string) file_get_contents(base_path('routes/api_v1.php')));

        foreach (self::PRODUCT_NAMES as $product) {
            $this->assertStringNotContainsString($product . 'controller', $routes);
        }
    }
}
