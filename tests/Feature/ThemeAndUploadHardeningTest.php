<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * users.theme is interpolated into `include base_path('themes/' . $theme .
 * '/config.php')` and into @include() view names by
 * linkstack/modules/theme.blade.php, so every write to it has to name a
 * theme that exists. POST /studio/theme is reachable by every customer --
 * only the zip-upload branch is admin-gated.
 *
 * Separately, AdminController::editSite had no validation on its logo and
 * favicon uploads, and the stored extension is content-guessed -- so HTML
 * or SVG content landed in a web-served directory as same-origin markup.
 */
class ThemeAndUploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cannot_set_a_traversing_theme(): void
    {
        $user = $this->makeUser(['theme' => 'default']);

        $this->actingAs($user)->post('/studio/theme', ['theme' => '../../storage/app']);

        $this->assertSame('default', $user->fresh()->theme);
    }

    public function test_customer_cannot_set_a_theme_that_is_not_installed(): void
    {
        $user = $this->makeUser(['theme' => 'default']);

        $this->actingAs($user)->post('/studio/theme', ['theme' => 'no-such-theme']);

        $this->assertSame('default', $user->fresh()->theme);
    }

    public function test_customer_can_still_select_an_installed_theme(): void
    {
        $user = $this->makeUser(['theme' => 'default']);
        $installed = mm_installed_themes();
        $this->assertNotEmpty($installed, 'fixture check: themes/ should not be empty');

        $this->actingAs($user)->post('/studio/theme', ['theme' => $installed[0]]);

        $this->assertSame($installed[0], $user->fresh()->theme);
    }

    public function test_admin_cannot_set_a_traversing_theme_on_another_user(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $victim = $this->makeUser(['theme' => 'default']);

        $this->actingAs($admin)->post('/admin/edit-user/' . $victim->id, [
            'name' => $victim->name,
            'email' => $victim->email,
            'littlelink_name' => $victim->littlelink_name,
            'littlelink_description' => '',
            'role' => 'user',
            'password' => '',
            'theme' => '../../config',
        ]);

        $this->assertSame('default', $victim->fresh()->theme);
    }

    public function test_public_page_renders_with_a_real_theme(): void
    {
        $installed = mm_installed_themes();
        $user = $this->makeUser(['theme' => $installed[0]]);

        $this->get('/@' . $user->littlelink_name)->assertSuccessful();
    }

    public function test_public_page_survives_a_junk_theme_already_in_the_db(): void
    {
        // Written directly, bypassing the controllers -- this is the
        // backstop in linkstack/modules/theme.blade.php, which basename()s
        // the value so it can never walk out of themes/.
        $user = $this->makeUser();
        User::where('id', $user->id)->update(['theme' => '../../storage/app']);

        $this->get('/@' . $user->littlelink_name)->assertSuccessful();
    }

    public function test_site_logo_upload_rejects_non_images(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/admin/site', [
            'message' => 'hi',
            'image' => UploadedFile::fake()->create('payload.html', 10, 'text/html'),
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertEmpty(
            glob(base_path('assets/linkstack/images/avatar_*.htm*')),
            'HTML content must never be written into the web-served image dir'
        );
    }

    /**
     * The one that actually matters. UploadedFile::fake() reports whatever
     * mime its filename implies, so a fake proves nothing about content
     * sniffing -- an early version of this suite wrote a real
     * <script>-bearing "avatar_*.png" into assets/linkstack/images/ and the
     * fake-based assertion still passed.
     *
     * This uses a REAL UploadedFile over a temp file, which is what
     * Laravel's mimes/image rules sniff, matching production.
     */
    public function test_real_upload_with_mismatched_content_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $tmp = tempnam(sys_get_temp_dir(), 'mmtest') . '.png';
        file_put_contents($tmp, '<html><script>alert(1)</script></html>');

        $response = $this->actingAs($admin)->post('/admin/site', [
            'message' => 'hi',
            // Named .png, declared image/png -- but the bytes are HTML.
            'image' => new \Illuminate\Http\UploadedFile($tmp, 'payload.png', 'image/png', null, true),
        ]);

        $response->assertSessionHasErrors('image');

        $written = glob(base_path('assets/linkstack/images/avatar_*')) ?: [];
        foreach ($written as $file) {
            $this->assertStringNotContainsString(
                '<script',
                (string) file_get_contents($file),
                'HTML content reached a web-served path'
            );
        }
        @unlink($tmp);
    }

    public function test_site_favicon_upload_rejects_svg(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $response = $this->actingAs($admin)->post('/admin/site', [
            'message' => 'hi',
            'icon' => UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml'),
        ]);

        $response->assertSessionHasErrors('icon');
        $this->assertEmpty(glob(base_path('assets/linkstack/images/favicon_*.svg')));
    }

    public function test_site_favicon_still_accepts_ico(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $before = glob(base_path('assets/linkstack/images/favicon_*')) ?: [];

        // Laravel's `image` rule rejects ICO, so the icon field must use
        // `file` + mimes -- otherwise this legitimate upload 422s.
        $this->actingAs($admin)->post('/admin/site', [
            'message' => 'hi',
            'icon' => UploadedFile::fake()->create('icon.ico', 10, 'image/vnd.microsoft.icon'),
        ])->assertSessionHasNoErrors();

        foreach (array_diff(glob(base_path('assets/linkstack/images/favicon_*')) ?: [], $before) as $written) {
            @unlink($written);
        }
    }
}
