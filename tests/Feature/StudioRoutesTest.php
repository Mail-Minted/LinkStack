<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Studio navigation after the "one styling home" merge: the theme
 * gallery lives on the Appearance pane; the old standalone routes and
 * #themes deep-links must land customers there, never on a dead page.
 */
class StudioRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_theme_redirects_customers_to_appearance(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/studio/theme')
            ->assertRedirect('/studio/edit#appearance');
    }

    public function test_studio_appearance_redirects_into_the_editor(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/studio/appearance')
            ->assertRedirect('/studio/edit#appearance');
    }

    public function test_editor_has_no_standalone_themes_tab(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/studio/edit');
        $response->assertSuccessful();
        // The tab row must not offer a separate Themes destination …
        $response->assertDontSee('data-mm-tab="themes"', false);
        // … while the gallery itself lives inside the Appearance pane.
        $response->assertSee('mm-theme-modal', false);
    }

    public function test_guests_cannot_open_the_studio(): void
    {
        $this->get('/studio/edit')->assertRedirect();
    }

    public function test_social_and_blocks_tabs_render_auto_save_wiring(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->get('/studio/edit');
        $response->assertSuccessful();

        // Social: inputs auto-save (instant-live) — the manual submit
        // button is gone; the form and the swappable chip-row region are
        // what the wiring script hooks.
        $response->assertDontSee('Save links');
        $response->assertSee('id="mm-social-form"', false);
        $response->assertSee('mm-social-order-region', false);

        // Blocks: saves go through fetch and refresh this region in
        // place instead of reloading the editor.
        $response->assertSee('mm-blocks-list-region', false);
        $response->assertSee('refreshBlocksList', false);
    }
}
