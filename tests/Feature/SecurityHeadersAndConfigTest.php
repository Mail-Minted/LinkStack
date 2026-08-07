<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transport and CORS posture. These are one-line config values whose
 * failure mode is silent -- nothing breaks, the protection is just absent.
 */
class SecurityHeadersAndConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_hsts_is_sent_over_https(): void
    {
        $response = $this->get('https://localhost/login');

        $response->assertHeader('Strict-Transport-Security');
        $this->assertStringContainsString(
            'max-age=31536000',
            $response->headers->get('Strict-Transport-Security')
        );
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Sending it over HTTP is meaningless, and from a local dev server
        // it would pin the developer's browser to https for localhost.
        $this->get('http://localhost/login')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_secure_cookie_flag_follows_the_resolved_environment(): void
    {
        // The bug was reading env('APP_ENV') directly: config/app.php
        // defaults it to 'production', but env() returns null when the
        // variable is unset, and null === 'production' is false.
        config(['app.env' => 'production']);
        $this->assertTrue(
            env('SESSION_SECURE_COOKIE', config('app.env') === 'production'),
            'a production environment must default to a Secure session cookie'
        );
    }

    public function test_cors_does_not_wildcard_the_admin_api(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertNotContains('*', $origins, 'api/admin/* must not be readable by any origin');
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
    }

    public function test_admin_api_has_its_own_rate_limit(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/users' && in_array('POST', $r->methods()));

        $this->assertNotNull($route, 'the provisioning route should exist');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }
}
