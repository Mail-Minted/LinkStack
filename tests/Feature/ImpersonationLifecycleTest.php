<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Impersonation state moved from a global users.auth_as column into the
 * session. The old design broke in three ways this pins shut:
 *
 *   - one admin impersonating blocked every other admin (single global slot)
 *   - the state outlived the session, so an admin who logged out mid-
 *     impersonation came back impersonating, failed the admin middleware,
 *     and had no exit control -- a lockout only fixable in the database
 *   - the exit was authenticated with users.remember_token, taken from the
 *     request body, and rendered into the page
 */
class ImpersonationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_and_exit_an_impersonation(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();

        $this->actingAs($admin)->post('/auth-as/' . $victim->id)->assertRedirect('dashboard');
        $this->assertSame($victim->id, Auth::id(), 'should now be the customer');

        $this->post('/auth-as')->assertRedirect('/admin/users/all');
        $this->assertSame($admin->id, Auth::id(), 'should be the admin again');
    }

    public function test_exiting_needs_no_token_from_the_request(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();
        $this->actingAs($admin)->post('/auth-as/' . $victim->id);

        // The old exit took an id and a token out of the body and called
        // Auth::loginUsingId() on that id. Who to return to is session
        // state now, so a forged body cannot redirect the return.
        $other = $this->makeUser(['role' => 'admin']);
        $this->post('/auth-as', ['id' => $other->id, 'token' => 'anything']);

        $this->assertSame($admin->id, Auth::id(), 'must return to the real impersonator');
    }

    public function test_logging_out_mid_impersonation_does_not_trap_the_admin(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();

        $this->actingAs($admin)->post('/auth-as/' . $victim->id);
        $this->post('/logout');

        // Next login: previously users.auth_as was still set, so the
        // middleware silently re-impersonated and /admin/* became
        // unreachable with no way back.
        $this->actingAs($admin)->get('/admin/users')->assertSuccessful();
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_a_second_admin_is_not_blocked_by_the_first(): void
    {
        $adminA = $this->makeUser(['role' => 'admin']);
        $adminB = $this->makeUser(['role' => 'admin']);
        $victimA = $this->makeUser();
        $victimB = $this->makeUser();

        $this->actingAs($adminA)->post('/auth-as/' . $victimA->id);
        $this->assertSame($victimA->id, Auth::id());

        // Separate session: the old global slot made this a no-op redirect.
        $this->flushSession();
        $this->actingAs($adminB)->post('/auth-as/' . $victimB->id);
        $this->assertSame($victimB->id, Auth::id());
    }

    public function test_impersonating_another_admin_is_refused(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $otherAdmin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)->post('/auth-as/' . $otherAdmin->id)
            ->assertRedirect('admin/users/all');

        $this->assertSame($admin->id, Auth::id(), 'must not become the other admin');
    }

    public function test_a_customer_cannot_start_an_impersonation(): void
    {
        $user = $this->makeUser();
        $victim = $this->makeUser();

        $this->actingAs($user)->post('/auth-as/' . $victim->id)
            ->assertRedirect(url('dashboard'));

        $this->assertSame($user->id, Auth::id());
    }

    public function test_a_forged_impersonator_id_in_the_session_is_ignored(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        // A non-admin id in the session must not produce a bar, and must
        // not let /auth-as switch identity to it.
        $this->actingAs($user)->withSession(['impersonator_id' => $other->id]);
        $this->get('/dashboard')->assertDontSee('class="ibar"', false);

        $this->post('/auth-as')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_token_is_left_alone(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();
        User::where('id', $admin->id)->update(['remember_token' => 'a-real-remember-me-token']);

        $this->actingAs($admin)->post('/auth-as/' . $victim->id);
        $this->get('/dashboard');
        $this->post('/auth-as');

        $this->assertSame(
            'a-real-remember-me-token',
            $admin->fresh()->remember_token,
            'impersonation must not clobber a genuine remember-me token'
        );
    }

    public function test_the_bar_no_longer_leaks_a_credential_into_the_page(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();
        User::where('id', $admin->id)->update(['remember_token' => 'a-real-remember-me-token']);

        $this->actingAs($admin)->post('/auth-as/' . $victim->id);
        $content = $this->get('/dashboard')->getContent();

        $this->assertStringContainsString('class="ibar"', $content, 'the bar should render');
        $this->assertStringNotContainsString('a-real-remember-me-token', $content);
        // The old bar always shipped the admin's remember_token as a hidden
        // field. Asserting the literal above is not enough on its own --
        // the old code had already overwritten the column with a fresh
        // random token, so the literal was absent for the wrong reason.
        $this->assertStringNotContainsString('name="token"', $content);
    }
}
