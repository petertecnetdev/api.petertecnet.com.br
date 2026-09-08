<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next, int $maxAge = 30, int $stale = 120): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD'], true) || ! $response->isSuccessful() || $response->headers->has('Set-Cookie')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content)) {
            return $response;
        }

        $etag = '"'.sha1($content).'"';
        $cacheControl = sprintf('public, max-age=%d, stale-while-revalidate=%d, stale-if-error=86400', max(0, $maxAge), max(0, $stale));

        if (trim((string) $request->headers->get('If-None-Match')) === $etag) {
            $notModified = response('', 304);
            $notModified->headers->set('ETag', $etag);
            $notModified->headers->set('Cache-Control', $cacheControl);
            $notModified->headers->set('Vary', 'Accept-Encoding, Origin');
            return $notModified;
        }

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', $cacheControl);
        $response->headers->set('Vary', 'Accept-Encoding, Origin');

        return $response;
    }
}
