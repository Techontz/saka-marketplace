<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response hardening.
 *
 * These belong at the edge (Cloudflare/nginx) too — this is the backstop that
 * survives a direct-to-origin request.
 *
 * No Content-Security-Policy here: this API only ever returns JSON, so a CSP
 * protects nothing. The CSP that matters belongs on the frontend, which is what
 * actually renders HTML.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * Escape <, >, & and quotes as \uXXXX in JSON bodies.
         *
         * Content-Type alone already stops a browser executing this response.
         * This is defence-in-depth for the case where a response is embedded
         * into an HTML document — an SSR payload inlined in a <script> block,
         * for instance — where an unescaped </script> in user text would break
         * out of the tag. Costs nothing: JSON decoders return identical strings.
         */
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(
                $response->getEncodingOptions()
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
        }

        // Stop browsers MIME-sniffing a JSON response into something executable.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Don't leak the full URL (including query strings, which carry search
        // terms and coordinates) to third-party origins.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // A JSON API is never legitimately framed.
        $response->headers->set('X-Frame-Options', 'DENY');

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        /*
         * Caching policy.
         *
         * Laravel's default is already `no-cache, private`, which keeps
         * responses out of shared caches. For AUTHENTICATED responses that is
         * upgraded to `no-store`: those bodies carry personal data (inquiries,
         * contact details, dashboards) and must not be written to disk by an
         * intermediary or the browser at all.
         *
         * Anonymous GETs are left alone deliberately — the public catalogue is
         * exactly what we want a CDN to cache later.
         */
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, private, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
        }

        // HSTS only over TLS — sending it on plain HTTP does nothing and
        // sending it in local dev poisons the developer's browser for months.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
