<?php

namespace Tests\Feature;

use App\Http\Controllers\AppearanceController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Theme switching / appearance reset / background image invariants.
 *
 * The uploaded background lives in TWO places: an override inside the
 * users.theme_customization sparse blob AND a file on disk that the
 * public bio renderer keys off directly (file existence, not the blob).
 * These tests pin the "both or neither" invariant — the July 2026 bug
 * was a theme switch clearing only the blob, leaving the photo
 * rendering over the new theme with the Remove button hidden.
 */
class AppearanceThemeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Explicit high ids so file operations in the REAL
     * assets/img/background-img directory can never touch a real
     * account's background (dev accounts are 1 and 241395).
     */
    private int $nextId = 990100;

    private function makeUserWithBackground(array $overrides = [])
    {
        $user = $this->makeUser(array_merge(['id' => $this->nextId++], $overrides));

        $dir = base_path('assets/img/background-img');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $user->id . '_test.jpg', 'fake-image-bytes');

        $user->theme_customization = json_encode([
            'background' => [
                'type'      => 'image',
                'image_url' => '/assets/img/background-img/' . $user->id . '_test.jpg',
            ],
        ]);
        $user->save();

        return $user;
    }

    private function backgroundFiles(int $userId): array
    {
        return glob(base_path('assets/img/background-img') . '/' . $userId . '_*') ?: [];
    }

    /**
     * Both naming eras for one user: "{id}_{time}.{ext}" (current) and
     * the stock "{id}.{ext}". Deliberately NOT "{id}*" — a bare wildcard
     * also matches OTHER accounts whose id merely starts with these
     * digits (id 1 matched a real 146700_*.jpg in the shared dir).
     */
    private function allBackgroundFiles(int $userId): array
    {
        $dir = base_path('assets/img/background-img');

        return array_merge(
            glob($dir . '/' . $userId . '_*') ?: [],
            glob($dir . '/' . $userId . '.*') ?: []
        );
    }

    protected function tearDown(): void
    {
        // Belt and braces: never leave test files in the shared dir.
        foreach (range(990100, $this->nextId) as $id) {
            AppearanceController::removeBackgroundFileIfPresent($id);
        }
        parent::tearDown();
    }

    public function test_theme_switch_clears_overrides_and_background_file(): void
    {
        $user = $this->makeUserWithBackground(['theme' => 'themeA']);

        $this->actingAs($user)
            ->post('/studio/theme', ['theme' => 'themeB'])
            ->assertRedirect('/studio/edit#appearance');

        $user->refresh();
        $this->assertSame('themeB', $user->theme);
        $this->assertNull($user->theme_customization, 'switching themes must clear appearance overrides');
        $this->assertCount(0, $this->backgroundFiles($user->id), 'switching themes must delete the uploaded background file');
    }

    public function test_theme_switch_also_removes_legacy_named_background_files(): void
    {
        // The stock admin uploader wrote {id}.{ext} (no underscore) —
        // those must not survive a switch either, or the old photo
        // keeps rendering (the bio page checks file existence).
        $user = $this->makeUser(['id' => $this->nextId++, 'theme' => 'themeA']);
        $dir = base_path('assets/img/background-img');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/' . $user->id . '.png', 'legacy-bytes');

        $this->actingAs($user)->post('/studio/theme', ['theme' => 'themeB']);

        $this->assertCount(0, $this->allBackgroundFiles($user->id), 'legacy-named background must be deleted on switch');
    }

    public function test_repicking_the_same_theme_keeps_overrides_and_file(): void
    {
        $user = $this->makeUserWithBackground(['theme' => 'themeA']);

        $this->actingAs($user)->post('/studio/theme', ['theme' => 'themeA']);

        $user->refresh();
        $this->assertNotNull($user->theme_customization, 're-picking the current theme must not wipe overrides');
        $this->assertCount(1, $this->backgroundFiles($user->id));
    }

    public function test_reset_clears_overrides_and_background_file(): void
    {
        $user = $this->makeUserWithBackground();

        $this->actingAs($user)
            ->post('/studio/appearance/reset')
            ->assertRedirect('/studio/edit#appearance');

        $user->refresh();
        $this->assertNull($user->theme_customization);
        $this->assertCount(0, $this->backgroundFiles($user->id), 'reset promises to clear the background — the file must go too');
    }

    public function test_theme_switch_marks_published_snapshot_dirty(): void
    {
        $user = $this->makeUserWithBackground([
            'theme'                    => 'themeA',
            'published_snapshot'       => json_encode(['user' => [], 'blocks' => []]),
            'has_unpublished_changes'  => false,
        ]);

        $this->actingAs($user)->post('/studio/theme', ['theme' => 'themeB']);

        $user->refresh();
        $this->assertTrue((bool) $user->has_unpublished_changes, 'background removal must flag the published page as stale');
    }

    public function test_avatar_backdrop_none_emits_transparent_css(): void
    {
        // "See-through" profile photos: transparency survives the upload
        // (PNG path), but generated themes paint a disc behind the
        // avatar. backdrop=none strips it via the override-CSS channel.
        $defaults = \App\Http\Controllers\AppearanceController::defaults();
        $css = \App\Services\AppearanceCss::build(['avatar' => ['backdrop' => 'none']], $defaults);
        $this->assertStringContainsString('.rounded-avatar', $css);
        $this->assertStringContainsString('background-color: transparent !important', $css);
        $this->assertStringContainsString('box-shadow: none !important', $css);

        // Default 'theme' emits nothing — a pristine page stays pristine.
        $cssDefault = \App\Services\AppearanceCss::build([], $defaults);
        $this->assertStringNotContainsString('background-color: transparent', $cssDefault);
    }

    public function test_custom_background_pins_text_color_even_when_untouched(): void
    {
        // The stock (no-theme) stylesheet flips all text black/white with
        // the VIEWER's OS color scheme. A user-chosen background with an
        // untouched text color must pin the effective text color, or
        // dark-mode visitors get white text on a light custom background.
        $defaults = \App\Http\Controllers\AppearanceController::defaults();
        $css = \App\Services\AppearanceCss::build([
            'background' => ['type' => 'solid', 'solid' => '#d8d5bb'],
        ], $defaults);

        $this->assertStringContainsString('background-color: #d8d5bb !important', $css);
        // body text + headings/description pinned to the manifest color
        $this->assertStringContainsString('color: #111111 !important', $css);
        $this->assertStringContainsString('.header-name, .header-description, h1', $css);
        // stock social icons scheme-flip too — pinned when no icon color mode chosen
        $this->assertStringContainsString('.social-icon { color: #111111 !important; }', $css);

        // A theme manifest's own text color is what gets pinned.
        $manifest = array_replace_recursive($defaults, ['colors' => ['text' => '#FFFFFF']]);
        $cssThemed = \App\Services\AppearanceCss::build([
            'background' => ['type' => 'solid', 'solid' => '#14181F'],
        ], $manifest);
        $this->assertStringContainsString('color: #FFFFFF !important', $cssThemed);

        // An explicit icon color mode keeps ownership of .social-icon.
        $cssIcons = \App\Services\AppearanceCss::build([
            'background'   => ['type' => 'solid', 'solid' => '#d8d5bb'],
            'social_icons' => ['color' => 'brand'],
        ], $defaults);
        $this->assertStringNotContainsString('.social-icon { color: #111111', $cssIcons);
    }

    public function test_oversized_upload_is_rejected_with_a_readable_message(): void
    {
        $user = $this->makeUser(['id' => $this->nextId++]);

        // The Background pill's JS renders the 422 JSON's `message`
        // verbatim — pin that the contract holds (a raw >2MB file only
        // reaches the server when the client-side resize was bypassed).
        $response = $this->actingAs($user)->postJson('/studio/appearance/background-image', [
            'image' => UploadedFile::fake()->image('huge.jpg', 800, 600)->size(3000),
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('message'));
        $this->assertStringContainsString('2MB', $response->json('message'));
        $this->assertCount(0, $this->backgroundFiles($user->id), 'rejected upload must not leave a file');
    }

    public function test_upload_stores_file_and_override_then_remove_clears_both(): void
    {
        $user = $this->makeUser(['id' => $this->nextId++]);

        $this->actingAs($user)
            ->post('/studio/appearance/background-image', [
                'image' => UploadedFile::fake()->image('bg.jpg', 600, 400),
            ])
            ->assertOk();

        $user->refresh();
        $blob = json_decode((string) $user->theme_customization, true);
        $this->assertSame('image', $blob['background']['type'] ?? null);
        $this->assertCount(1, $this->backgroundFiles($user->id), 'upload must write the file');

        $this->actingAs($user)
            ->post('/studio/appearance/background-image/remove')
            ->assertOk();

        $user->refresh();
        $blobAfter = json_decode((string) $user->theme_customization, true) ?? [];
        $this->assertArrayNotHasKey('background', $blobAfter, 'remove must drop the blob override');
        $this->assertCount(0, $this->backgroundFiles($user->id), 'remove must delete the file');
    }
}
