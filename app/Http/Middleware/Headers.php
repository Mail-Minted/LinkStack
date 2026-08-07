<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Headers
{
    public function handle(Request $request, Closure $next)
    {
        // Per-request CSP nonce — generated BEFORE the view renders so
        // inline <script nonce="{{ csp_nonce() }}"> can pick it up. Read
        // back via the csp_nonce() helper (request attribute).
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);

        // Check if FORCE_HTTPS is set to true
        if (env('FORCE_HTTPS') == 'true') {
            \URL::forceScheme('https'); // Force HTTPS
        }

        // Check if FORCE_ROUTE_HTTPS is set to true. Use the proxy-aware
        // secure() check, NOT $_SERVER['HTTPS']: behind a TLS-terminating
        // proxy (Railway et al.) the raw var is never set, which made
        // every request redirect to itself forever.
        if (env('FORCE_ROUTE_HTTPS') == 'true' && !$request->secure()) {
            // Build the redirect host from the app's OWN configured URL,
            // not $_SERVER['HTTP_HOST'] — the Host header is attacker-
            // controlled, so the old code was an open redirect (Host:
            // evil.com -> Location: https://evil.com/...). If APP_URL
            // isn't configured we do NOT fall back to the request host;
            // continuing over the current scheme is safer than
            // redirecting to an untrusted destination.
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if ($appHost) {
                header('Location: https://' . $appHost . $request->getRequestUri());
                exit();
            }
        }

        $response = $next($request);

        // Some responses (streamed downloads, redirects handled above)
        // may not carry a headers bag we can set on; guard defensively.
        if (!method_exists($response, 'headers') && !isset($response->headers)) {
            return $response;
        }

        // ---- Baseline security headers (every response) ----
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS, but only on connections that already arrived over TLS --
        // sending it over plain HTTP is meaningless, and sending it from a
        // local HTTP dev server would pin the developer's browser to https
        // for localhost. No preload directive: that is a one-way door
        // (removal takes months), so it should be a deliberate choice
        // rather than something a security pass turns on.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // Enforced baseline (every response): <base> injection + plugin
        // embedding. upgrade-insecure-requests when HTTPS is forced.
        $baseCsp = "base-uri 'self'; object-src 'none'";
        if (env('FORCE_HTTPS') == 'true') {
            $baseCsp .= '; upgrade-insecure-requests';
        }

        // The script + supporting directives (CSP-HARDENING-PLAN.md
        // Phase 0/1). script-src is the TIGHT one — 'self' + this
        // request's nonce + the GA origin — so an injected inline
        // <script> without the nonce won't run. The supporting
        // directives are present but permissive-https so media embeds,
        // fonts, images and GA keep working; the XSS win is script-src,
        // not locking down images. style-src keeps 'unsafe-inline' (the
        // app renders inline styles everywhere; nonces don't apply to
        // style attributes; style injection is low risk).
        $scriptCsp = implode('; ', [
            "script-src 'self' 'nonce-{$nonce}' https://www.googletagmanager.com",
            "style-src 'self' 'unsafe-inline' https:",
            // blob: lets the editor load a user-picked file into an <img>
            // for client-side resize/crop before upload (appearance.js —
            // background + avatar). Same-origin, page-created; not a script
            // vector. Without it those object-URL loads fail ("Couldn't
            // read image").
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            // 'self' so the studio can frame its own bio pages (the live
            // preview iframe — same-origin, http in local dev); https for
            // the media-embed blocks (youtube/spotify/twitch/…).
            "frame-src 'self' https:",
            "connect-src 'self' https:",
        ]);

        // script-src is ENFORCED where every inline script is nonced and
        // every inline handler refactored: the public bio page (Phase 1)
        // and the studio / dashboard / admin (Phase 2). The bio page is
        // the stored-XSS target; the studio is the authenticated surface.
        $action = optional($request->route())->getActionName();
        $isBioPage = is_string($action) && str_contains($action, 'littlelink');
        $isAuthArea = $request->is('studio', 'studio/*', 'dashboard', 'dashboard/*', 'admin', 'admin/*');

        if ($isBioPage || $isAuthArea) {
            $csp = $baseCsp . '; ' . $scriptCsp;
            if ($isAuthArea) {
                // Clickjacking protection on the authenticated surface;
                // bio pages stay frameable so customers can embed them.
                $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
                $csp .= "; frame-ancestors 'self'";
            }
            $response->headers->set('Content-Security-Policy', $csp . '; report-uri /csp-report');
        } else {
            // Not yet hardened (auth, home, installer, errors): baseline
            // enforced, script-src Report-Only so violations are visible
            // without breaking those pages.
            $response->headers->set('Content-Security-Policy', $baseCsp);
            $response->headers->set(
                'Content-Security-Policy-Report-Only',
                $scriptCsp . '; report-uri /csp-report'
            );
        }

        return $response;
    }
}
