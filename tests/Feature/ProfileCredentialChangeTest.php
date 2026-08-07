<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * /studio/profile could change the e-mail and the password with no
 * re-authentication, so a stolen session converted straight into permanent
 * account ownership -- the address is what a reset link targets, and a new
 * password locks the real owner out.
 *
 * Note the display name deliberately does NOT require the current
 * password: customers are SSO-provisioned with a random local password
 * they never see, so gating a harmless field behind it would lock them out.
 */
class ProfileCredentialChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->makeUser();
        $before = $user->password;

        $this->actingAs($user)
            ->post('/studio/profile', ['password' => 'a-brand-new-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($before, $user->fresh()->password, 'password must be unchanged');
    }

    public function test_email_change_requires_the_current_password(): void
    {
        $user = $this->makeUser();
        $before = $user->email;

        $this->actingAs($user)
            ->post('/studio/profile', ['email' => 'attacker@evil.test'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($before, $user->fresh()->email);
    }

    public function test_a_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/studio/profile', [
                'password' => 'a-brand-new-password',
                'current_password' => 'not-the-right-one',
            ])
            ->assertSessionHasErrors('current_password');
    }

    public function test_correct_current_password_allows_the_change(): void
    {
        // makeUser() hashes 'secret-password'.
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/studio/profile', [
                'password' => 'a-brand-new-password',
                'current_password' => 'secret-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_changing_the_email_clears_verification(): void
    {
        $user = $this->makeUser();
        $this->assertNotNull($user->email_verified_at);

        $this->actingAs($user)->post('/studio/profile', [
            'email' => 'new-address@example.test',
            'current_password' => 'secret-password',
        ]);

        $fresh = $user->fresh();
        $this->assertSame('new-address@example.test', $fresh->email);
        $this->assertNull($fresh->email_verified_at, 'a changed address is unproven');
    }

    public function test_display_name_alone_needs_no_reauthentication(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/studio/profile', ['name' => 'A New Name'])
            ->assertSessionHasNoErrors();

        $this->assertSame('A New Name', $user->fresh()->name);
    }

    public function test_a_name_no_longer_swallows_a_password_change(): void
    {
        $user = $this->makeUser();

        // The old elseif chain silently discarded the password whenever a
        // name was submitted alongside it.
        $this->actingAs($user)->post('/studio/profile', [
            'name' => 'Renamed',
            'password' => 'a-brand-new-password',
            'current_password' => 'secret-password',
        ]);

        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertTrue(Hash::check('a-brand-new-password', $fresh->password), 'both changes must apply');
    }
}
