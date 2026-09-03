<?php

namespace App\Domain\Identity\Services;

use App\Models\Application;
use Illuminate\Http\Request;

class IdentityApplicationResolver
{
    public function resolve(Request $request, mixed $identifier = null): ?Application
    {
        $attribute = $request->attributes->get('application');
        if ($attribute instanceof Application) {
            return $attribute;
        }

        $candidate = $identifier
            ?? $request->input('application')
            ?? $request->input('app_slug')
            ?? $request->header('X-Peter-Application');

        if ($candidate !== null && $candidate !== '') {
            return Application::query()
                ->where('is_active', true)
                ->where(function ($query) use ($candidate) {
                    $query->where('slug', (string) $candidate);
                    if (is_numeric($candidate)) {
                        $query->orWhereKey((int) $candidate);
                    }
                })
                ->first();
        }

        $origin = $request->headers->get('origin');
        if (! $origin) {
            return null;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        if (! is_string($originHost) || $originHost === '') {
            return null;
        }

        return Application::query()
            ->where('is_active', true)
            ->get()
            ->first(function (Application $application) use ($originHost) {
                $host = parse_url((string) $application->url, PHP_URL_HOST);
                return is_string($host) && strcasecmp($host, $originHost) === 0;
            });
    }
}
