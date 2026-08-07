<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * AdminController::updateThemes reads a "Source code:" URL out of each
 * installed theme's readme.md, fetches it, and downloads + extracts an
 * archive from it. readme.md arrives with any uploaded theme, so that URL
 * is untrusted input, and both ends of the chain were unguarded:
 *
 *   - the host test was strpos($url, 'github.com'), which only requires the
 *     substring somewhere past position 0, so "http://attacker.example/x
 *     #github.com" passed and the attacker's own host answered
 *   - extractTo() ran with no Zip Slip check at all, so a crafted archive
 *     could write outside themes/ -- into a servable path
 *
 * and the whole thing hung off a GET route with no CSRF token.
 */
class ThemeUpdaterHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider sourceUrlProvider */
    public function test_only_github_repo_urls_are_accepted(?string $expected, string $url): void
    {
        $this->assertSame($expected, mm_theme_source_repo($url));
    }

    public static function sourceUrlProvider(): array
    {
        return [
            'plain repo'          => ['https://github.com/owner/repo', 'https://github.com/owner/repo'],
            'surrounding space'   => ['https://github.com/Owner/Repo-Name', '  https://github.com/Owner/Repo-Name  '],
            // The exact bypass: the fragment is dropped when fetching, so
            // attacker.example answered while passing the substring test.
            'fragment bypass'     => [null, 'http://attacker.example/x#github.com'],
            'query bypass'        => [null, 'https://attacker.example/x?a=github.com'],
            'suffix host'         => [null, 'https://github.com.evil.tld/owner/repo'],
            'plaintext scheme'    => [null, 'http://github.com/owner/repo'],
            'traversal in path'   => [null, 'https://github.com/owner/repo/../../etc'],
            'not a repo path'     => [null, 'https://github.com/owner'],
            'other github host'   => [null, 'https://raw.githubusercontent.com/owner/repo'],
            'empty'               => [null, ''],
        ];
    }

    private function makeZip(string $path, array $entries): void
    {
        @unlink($path);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name) {
            $zip->addFromString($name, 'x');
        }
        $zip->close();
    }

    public function test_zip_guard_accepts_a_normal_theme_archive(): void
    {
        $path = sys_get_temp_dir() . '/mm-safe-theme.zip';
        $this->makeZip($path, ['MyTheme/config.php', 'MyTheme/readme.md']);

        $zip = new ZipArchive();
        $zip->open($path);
        $this->assertTrue(mm_zip_entries_are_safe($zip));
        $zip->close();
        @unlink($path);
    }

    public function test_zip_guard_rejects_traversal_entries(): void
    {
        foreach ([
            '../../routes/web.php',
            'MyTheme/../../public/shell.php',
            '/etc/passwd',
            'MyTheme\\..\\..\\public\\shell.php',
        ] as $evil) {
            $path = sys_get_temp_dir() . '/mm-evil-theme.zip';
            $this->makeZip($path, ['MyTheme/config.php', $evil]);

            $zip = new ZipArchive();
            $zip->open($path);
            $this->assertFalse(mm_zip_entries_are_safe($zip), "should reject: $evil");
            $zip->close();
            @unlink($path);
        }
    }

    public function test_theme_update_is_not_reachable_by_get(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // It downloads and extracts archives, so an admin merely loading a
        // URL must not be able to trigger it.
        $this->actingAs($admin)->get('/update/theme')->assertStatus(405);
    }

    public function test_theme_updater_page_still_renders(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // The page runs the same source-URL parsing over every installed
        // theme's readme, so a mistake there takes the whole page down.
        $response = $this->actingAs($admin)->get('/theme-updater');

        $response->assertSuccessful();
        // The "Update all themes" control is a CSRF-carrying POST now.
        $response->assertSee('action="' . route('updateThemes') . '"', false);
        $response->assertSee('name="_token"', false);
    }

    public function test_theme_update_is_admin_only(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/update/theme')->assertRedirect(url('dashboard'));
    }
}
