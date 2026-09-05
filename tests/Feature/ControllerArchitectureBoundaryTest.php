<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ControllerArchitectureBoundaryTest extends TestCase
{
    /**
     * Baseline used only to grandfather controllers that already violated the
     * boundary before this architecture contract existed. Any new controller,
     * or any subsequent edit to a legacy controller, must satisfy the rules.
     */
    private const LEGACY_BASELINE = '34457ab71d3a740121cbea1c99fff3e5a8ecda32';

    public function test_new_or_modified_controllers_are_transport_only(): void
    {
        $roots = [
            app_path('Domain'),
            app_path('Http/Controllers'),
        ];
        $violations = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) continue;

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), 'Controller.php')) continue;
                if (str_contains(str_replace('\\', '/', $file->getPathname()), '/Domain/')
                    && ! str_contains(str_replace('\\', '/', $file->getPathname()), '/Http/Controllers/')) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                $reasons = $this->forbiddenReasons($contents);
                if ($reasons === []) continue;

                $relative = $this->relative($file->getPathname());
                $baseline = $this->baselineContents($relative);

                // Existing debt is tolerated only while the file stays byte-for-byte
                // equivalent (ignoring trailing whitespace) to the contract baseline.
                // Touching it requires paying down the architectural debt.
                if ($baseline !== null && rtrim($baseline) === rtrim($contents)) {
                    continue;
                }

                $violations[] = $relative.' ['.implode(', ', $reasons).']';
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "Controllers must stay transport-only. Move persistence, reusable queries, storage, mail, encryption, integrations and controller-to-controller orchestration to Services/Actions.\n".
            implode("\n", $violations)
        );
    }

    public function test_workforce_controller_is_a_clean_reference_implementation(): void
    {
        $path = app_path('Domain/Workforce/Http/Controllers/TeamMemberController.php');
        $contents = File::get($path);

        $this->assertSame([], $this->forbiddenReasons($contents));
        $this->assertStringContainsString('TeamMemberService', $contents);
        $this->assertStringNotContainsString('App\\Models\\', $contents);
        $this->assertStringNotContainsString('Support\\Facades\\Mail', $contents);
    }

    private function forbiddenReasons(string $contents): array
    {
        $reasons = [];
        $facades = ['DB', 'Storage', 'Mail', 'Crypt', 'Http'];

        foreach ($facades as $facade) {
            if (preg_match('/use\\s+Illuminate\\\\Support\\\\Facades\\\\'.$facade.'\\s*;/', $contents)
                || preg_match('/\\b'.$facade.'::/', $contents)) {
                $reasons[] = 'facade:'.$facade;
            }
        }

        if (preg_match('/app\\s*\\(\\s*[A-Za-z0-9_\\\\]+Controller::class\\s*\\)/', $contents)) {
            $reasons[] = 'controller-to-controller';
        }

        preg_match_all('/use\\s+App\\\\Models\\\\([A-Za-z0-9_]+)\\s*;/', $contents, $matches);
        foreach ($matches[1] ?? [] as $model) {
            if (preg_match('/\\b'.preg_quote($model, '/').'::(?:query|create|where|with|find|findOrFail|firstOrCreate|updateOrCreate|insert|destroy|whereKey)\\s*\\(/', $contents)) {
                $reasons[] = 'model-workflow:'.$model;
            }
        }

        return array_values(array_unique($reasons));
    }

    private function baselineContents(string $relative): ?string
    {
        $spec = self::LEGACY_BASELINE.':'.$relative;
        $output = [];
        $exitCode = 0;
        exec('git show '.escapeshellarg($spec).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        return implode("\n", $output);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR));
    }
}
