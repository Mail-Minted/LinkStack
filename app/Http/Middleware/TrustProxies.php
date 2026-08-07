<?php

namespace App\Http\Middleware;

use Fideloper\Proxy\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Railway (and any PaaS) terminates TLS at its edge and forwards plain
     * HTTP with X-Forwarded-Proto. Without trusting that header, the
     * FORCE_HTTPS redirect loops forever. The container is only reachable
     * through the platform proxy, so trusting all upstreams is safe here.
     *
     * Trusting every upstream is a deployment assumption, not a property
     * of the app: on any host that exposes the container port directly,
     * X-Forwarded-For becomes client-controlled (defeating the IP-keyed
     * login and API rate limiters) and so does X-Forwarded-Host.
     *
     * Left as '*' by default so the Railway deployment keeps working
     * unchanged, but TRUSTED_PROXIES can now narrow it per environment --
     * e.g. TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12 -- without a code
     * change. Deliberately not narrowed here: picking the wrong range
     * breaks HTTPS detection in a way that is not obvious from the app.
     *
     * @var array|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $configured = trim((string) env('TRUSTED_PROXIES', '*'));
        $this->proxies = $configured === '*' || $configured === ''
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB;
}
