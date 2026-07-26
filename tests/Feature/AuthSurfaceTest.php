<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant: the ONLY way to get a LinkStack account is Mail Minted's
 * checkout provisioning. Every self-service account-creation surface must
 * be dead-ended so nobody can mint a bio page without buying a bundle.
 *
 * Register / password-reset / email-verification are sealed in
 * routes/auth.php. Social login is sealed in routes/web.php — it was the
 * subtle one: SocialLoginController auto-creates a user on first
 * authentication, so an enabled provider would silently reopen public
 * signup. These tests fail on the old code (routes returned redirects /
 * 200, not 404).
 */
class AuthSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_social_login_redirect_is_dead(): void
    {
        $this->get('/social-auth/google')->assertNotFound();
    }

    public function test_social_login_callback_is_dead(): void
    {
        $this->get('/social-auth/google/callback')->assertNotFound();
    }

    public function test_register_is_dead(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_password_reset_request_is_dead(): void
    {
        $this->get('/forgot-password')->assertNotFound();
    }
}
