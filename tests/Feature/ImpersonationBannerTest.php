<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The impersonation bar is built as a raw HTML heredoc in
 * App\Http\Middleware\Impersonate and spliced into the response body, so
 * nothing escapes it for us. It interpolates the impersonated account's
 * display name -- which that account's own owner controls -- straight into
 * the markup, giving any customer stored XSS against an admin the moment
 * the admin impersonated them.
 */
class ImpersonationBannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Put the pair into the state Impersonate::handle expects: an admin
     * row carrying auth_as, logged in as that admin.
     */
    private function startImpersonating(User $admin, User $victim): void
    {
        User::where('id', $admin->id)->update(['auth_as' => $victim->id]);
    }

    public function test_display_name_is_escaped_in_the_banner(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();

        // Written straight to the column: the sink must be safe no matter
        // how the value got there.
        User::where('id', $victim->id)->update([
            'name' => '<script>alert(1)</script>',
        ]);
        $this->startImpersonating($admin, $victim);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertDontSee('<script>alert(1)</script>', false);
        // ...and the escaped form is what actually renders.
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_image_onerror_payload_is_escaped(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();

        User::where('id', $victim->id)->update([
            'name' => '"><img src=x onerror=alert(1)>',
        ]);
        $this->startImpersonating($admin, $victim);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    /**
     * Markup-integrity guard for names containing "$1" / "\1".
     *
     * Honest caveat: this passes against the old preg_replace version too,
     * so it is NOT what demonstrates that fix. The backreference hazard is
     * real and reproducible in isolation --
     *
     *   preg_replace('/<body([^>]*)>/', "<body\$1>{$html}", $content)
     *
     * with $html containing "$1" and a <body> carrying attributes splices
     * those attributes into the output -- but it does not reproduce on this
     * particular page, so this stands only as a regression guard.
     */
    public function test_dollar_backreference_in_a_name_does_not_corrupt_markup(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();

        User::where('id', $victim->id)->update(['name' => 'Ann $1 O\\1Brien']);
        $this->startImpersonating($admin, $victim);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertSuccessful();
        $response->assertSee('Ann $1 O\\1Brien', false);
        // The <body ...> attributes must survive intact around the bar.
        $this->assertStringContainsString('<body', $response->getContent());
        $this->assertStringContainsString('class="ibar"', $response->getContent());
    }

    public function test_exit_button_script_carries_the_csp_nonce(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser();
        $this->startImpersonating($admin, $victim);

        $content = $this->actingAs($admin)->get('/dashboard')->getContent();

        // script-src is enforced with no unsafe-inline on the studio /
        // dashboard area, so an un-nonced handler is dead markup.
        $this->assertStringContainsString('id="ibarExit"', $content);
        $this->assertStringNotContainsString('onclick="document.getElementById', $content);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">/', $content);
    }

    public function test_profile_name_is_stored_as_plain_text(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/profile', [
            'name' => '<b>Bold</b> Name',
        ]);

        $this->assertSame('Bold Name', $user->fresh()->name);
    }

    public function test_profile_name_length_is_bounded(): void
    {
        $user = $this->makeUser();
        $original = $user->name;

        $this->actingAs($user)
            ->post('/studio/profile', ['name' => Str::repeat('a', 300)])
            ->assertSessionHasErrors('name');

        $this->assertSame($original, $user->fresh()->name);
    }

    public function test_saving_an_unchanged_name_is_not_a_uniqueness_error(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/studio/profile', ['name' => $user->name])
            ->assertSessionHasNoErrors();
    }
}
