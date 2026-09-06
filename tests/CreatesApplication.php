<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $environment = (string) $app['config']->get('app.env');
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $safeSqliteDatabase = $connection === 'sqlite'
            && ($database === ':memory:' || str_starts_with($database, '/tmp/'));
        $safeNamedTestDatabase = $connection !== 'sqlite'
            && preg_match('/(?:^|[_-])(test|testing)(?:$|[_-])/i', $database) === 1;

        if ($environment !== 'testing'
            || strcasecmp($database, 'petertecnet') === 0
            || (! $safeSqliteDatabase && ! $safeNamedTestDatabase)) {
            throw new \RuntimeException(sprintf(
                'Unsafe test database refused: environment=%s connection=%s database=%s',
                $environment,
                $connection,
                $database
            ));
        }

        return $app;
    }
}
