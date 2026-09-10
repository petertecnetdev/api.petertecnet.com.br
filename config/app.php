<?php

use Illuminate\Support\Facades\Facade;

$providers = [
    Tymon\JWTAuth\Providers\LaravelServiceProvider::class,
    Intervention\Image\ImageServiceProvider::class,

    Illuminate\Auth\AuthServiceProvider::class,
    Illuminate\Broadcasting\BroadcastServiceProvider::class,
    Illuminate\Bus\BusServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class,
    Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
    Illuminate\Cookie\CookieServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Encryption\EncryptionServiceProvider::class,
    Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Foundation\Providers\FoundationServiceProvider::class,
    Illuminate\Hashing\HashServiceProvider::class,
    Illuminate\Mail\MailServiceProvider::class,
    Illuminate\Notifications\NotificationServiceProvider::class,
    Illuminate\Pagination\PaginationServiceProvider::class,
    Illuminate\Pipeline\PipelineServiceProvider::class,
    Illuminate\Queue\QueueServiceProvider::class,
    Illuminate\Redis\RedisServiceProvider::class,
    Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
    Illuminate\Session\SessionServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\View\ViewServiceProvider::class,

    /*
     * Package Service Providers...
     */

    /*
     * Application Service Providers...
     */
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\BroadcastServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\SubscriptionServiceProvider::class,

    Barryvdh\DomPDF\ServiceProvider::class,
];

// Se não estiver em ambiente local, o Collision não é adicionado.
// Portanto, certifique-se de que em staging o APP_ENV esteja configurado diferente de "local".
if (env('APP_ENV') === 'local') {
    $providers[] = NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider::class;
}

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'asset_url' => env('ASSET_URL'),

    'timezone' => 'America/Sao_Paulo',

    'locale' => 'pt_BR',

    'fallback_locale' => 'pt_BR',

    'faker_locale' => 'pt_BR',

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => 'file',
    ],

    'providers' => $providers,
    'aliases' => Facade::defaultAliases()->merge([
        'Image' => Intervention\Image\Facades\Image::class,
        'Google_Client' => \Google_Client::class,
        'PDF' => Barryvdh\DomPDF\Facade\Pdf::class,
        'JWTAuth' => Tymon\JWTAuth\Facades\JWTAuth::class,
        'JWTFactory' => Tymon\JWTAuth\Facades\JWTFactory::class,
    ])->toArray(),

];