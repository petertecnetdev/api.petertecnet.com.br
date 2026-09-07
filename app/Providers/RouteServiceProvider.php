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
            Route::middleware('api')->prefix('api')->group(base_path('routes/support.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/identity.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/api_v1.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/public_user_profiles.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/event_agenda.php'));
            Route::prefix('api')->group(base_path('routes/event_duplication.php'));
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
            Route::middleware('api')->prefix('api')->group(base_path('routes/admin_application_operations.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/admin_establishment_resources.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/cognition.php'));
            Route::middleware('api')->prefix('api')->group(base_path('routes/market_data.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        });
    }

    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $userId = $this->rateLimitUserId($request);
            $ip = $request->ip();
            $appKey = $this->rateLimitApplicationKey($request);
            $isAuthRequest = $request->is('api/auth/*');

            if ($userId) {
                return [
                    Limit::perMinute($isAuthRequest ? 10000 : 6000)
                        ->by('api:user-app:'.$userId.':'.$appKey),
                    Limit::perMinute($isAuthRequest ? 30000 : 20000)
                        ->by('api:user-global:'.$userId),
                ];
            }

            return [
                Limit::perMinute($isAuthRequest ? 6000 : 1200)
                    ->by('api:ip-app:'.$ip.':'.$appKey),
                Limit::perMinute($isAuthRequest ? 12000 : 6000)
                    ->by('api:ip-global:'.$ip),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower(trim((string) ($request->input('username') ?: $request->input('email'))));
            $identifierKey = $identifier !== '' ? hash('sha256', $identifier) : 'missing-identifier';
            $ip = $request->ip();
            $appKey = $this->rateLimitApplicationKey($request);

            $tooManyAttemptsResponse = static function (Request $request, array $headers) {
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                return response()->json([
                    'message' => 'Muitas tentativas de acesso. Aguarde alguns segundos e tente novamente.',
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            };

            return [
                Limit::perMinute(20)
                    ->by('login:'.$ip.':'.$identifierKey)
                    ->response($tooManyAttemptsResponse),
                Limit::perMinute(120)
                    ->by('login-app-ip:'.$appKey.':'.$ip)
                    ->response($tooManyAttemptsResponse),
                Limit::perMinute(300)
                    ->by('login-ip-global:'.$ip)
                    ->response($tooManyAttemptsResponse),
            ];
        });

        RateLimiter::for('google-login', function (Request $request) {
            $ip = $request->ip();
            $appKey = $this->rateLimitApplicationKey($request);

            $tooManyAttemptsResponse = static function (Request $request, array $headers) {
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                return response()->json([
                    'message' => 'Muitas tentativas de login com Google. Aguarde alguns segundos e tente novamente.',
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            };

            return [
                Limit::perMinute(60)
                    ->by('google-login-app-ip:'.$appKey.':'.$ip)
                    ->response($tooManyAttemptsResponse),
                Limit::perMinute(300)
                    ->by('google-login-ip-global:'.$ip)
                    ->response($tooManyAttemptsResponse),
            ];
        });

        RateLimiter::for('market-read', function (Request $request) {
            $userId = $this->rateLimitUserId($request);
            $appKey = $this->rateLimitApplicationKey($request);

            if ($userId) {
                return Limit::perMinute(1800)
                    ->by('market-read:user-app:'.$userId.':'.$appKey);
            }

            return Limit::perMinute(900)
                ->by('market-read:ip-app:'.$request->ip().':'.$appKey);
        });

        RateLimiter::for('telemetry', function (Request $request) {
            $userId = $this->rateLimitUserId($request);
            $appKey = $this->rateLimitApplicationKey($request);

            if ($userId) {
                return Limit::perMinute(3000)
                    ->by('telemetry:user-app:'.$userId.':'.$appKey);
            }

            return Limit::perMinute(1200)
                ->by('telemetry:ip-app:'.$request->ip().':'.$appKey);
        });

        RateLimiter::for('support-write', function (Request $request) {
            $userId = $this->rateLimitUserId($request);
            $appKey = $this->rateLimitApplicationKey($request);
            $key = $userId ? 'user:'.$userId : 'ip:'.$request->ip();

            return Limit::perMinute($userId ? 60 : 12)
                ->by('support-write:'.$key.':'.$appKey);
        });

        RateLimiter::for('support-read', function (Request $request) {
            $userId = $this->rateLimitUserId($request);
            $appKey = $this->rateLimitApplicationKey($request);
            $key = $userId ? 'user:'.$userId : 'ip:'.$request->ip();

            return Limit::perMinute($userId ? 300 : 90)
                ->by('support-read:'.$key.':'.$appKey);
        });
    }

    private function rateLimitUserId(Request $request): int|string|null
    {
        try {
            return $request->user('api')?->getAuthIdentifier();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function rateLimitApplicationKey(Request $request): string
    {
        $header = strtolower(trim((string) $request->header('X-Peter-App', '')));
        $routeApplication = strtolower(trim((string) $request->route('application')));
        $value = $header !== '' ? $header : $routeApplication;

        if ($value === '') {
            return 'unknown';
        }

        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: 'unknown';

        return substr(trim($normalized, '-'), 0, 80) ?: 'unknown';
    }
}
