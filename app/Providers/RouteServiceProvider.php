<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/email_verification_deferral.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/account.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/identity.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_v1.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/social_feed.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/event_pass_transfer.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/organization_experience.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/acquisition.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/workforce.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/commerce_fulfillment.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/leasing.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/leasing_context.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/documents.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/branding.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/content.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/participants.php'));
            Route::prefix('api')->group(base_path('routes/compatibility.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/event_attendance.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/revenue.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/finance.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/ecosystem.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/cognition.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/market_data.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $userId = null;

            try {
                $userId = $request->user('api')?->getAuthIdentifier();
            } catch (\Throwable $exception) {
                // Authentication middleware will handle invalid/expired credentials later.
                // Rate limiting must never turn an auth failure into a 500 response.
            }

            if ($userId) {
                return Limit::perMinute(3000)->by('user:'.$userId);
            }

            return Limit::perMinute(600)->by('ip:'.$request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $emailKey = $email !== '' ? hash('sha256', $email) : 'missing-email';
            $ip = $request->ip();

            $tooManyAttemptsResponse = static function (Request $request, array $headers) {
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                return response()->json([
                    'message' => 'Muitas tentativas de acesso. Aguarde alguns segundos e tente novamente.',
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            };

            return [
                Limit::perMinute(20)
                    ->by('login:'.$ip.':'.$emailKey)
                    ->response($tooManyAttemptsResponse),
                Limit::perMinute(60)
                    ->by('login-ip:'.$ip)
                    ->response($tooManyAttemptsResponse),
            ];
        });
    }
}
