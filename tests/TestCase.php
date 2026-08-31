<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * The HTTP test kernel reuses the same application instance between
     * requests. JWT guards cache the resolved user, while real PHP-FPM
     * requests start with a fresh guard. Reset guards before each simulated
     * request so every Authorization header is authenticated independently.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (isset($this->app) && $this->app->bound('auth')) {
            $this->app['auth']->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
