<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /studio/linkparamform_part re-renders a block's saved fields into the
 * editor, and for some blocks those fields are secrets — the newsletter
 * block keeps its Mailchimp API key in type_params and form.blade.php
 * puts it straight back into an input value.
 *
 * The route was the one link route without an ownership check, so any
 * logged-in customer could read any other customer's block config by id.
 * These pin the scope so it cannot regress.
 */
class BlockParamFormAccessTest extends TestCase
{
    use RefreshDatabase;

    private function newsletterBlock($owner): Link
    {
        $link = new Link();
        $link->user_id = $owner->id;
        $link->title = 'Join the list';
        $link->link = 'Subscribe';
        $link->button_id = 1;
        $link->type = 'newsletter_signup';
        $link->type_params = json_encode([
            'api_key' => 'totally-secret-abc123-us1',
            'list_id' => 'abc123list',
        ]);
        $link->order = 1;
        $link->save();

        return $link;
    }

    public function test_owner_can_load_their_own_block_params(): void
    {
        $owner = $this->makeUser();
        $link = $this->newsletterBlock($owner);

        $this->actingAs($owner)
            ->get("/studio/linkparamform_part/newsletter_signup/{$link->id}")
            ->assertSuccessful()
            ->assertSee('totally-secret-abc123-us1', false);
    }

    public function test_another_user_cannot_read_someone_elses_block_params(): void
    {
        $owner = $this->makeUser();
        $attacker = $this->makeUser();
        $link = $this->newsletterBlock($owner);

        $response = $this->actingAs($attacker)
            ->get("/studio/linkparamform_part/newsletter_signup/{$link->id}");

        $response->assertForbidden();
        $response->assertDontSee('totally-secret-abc123-us1', false);
    }

    public function test_admins_get_no_backdoor_into_another_users_secrets(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser(['role' => 'admin']);
        $link = $this->newsletterBlock($owner);

        // Nothing in the admin UI edits another user's block params, so an
        // admin session must not widen the scope either.
        $this->actingAs($admin)
            ->get("/studio/linkparamform_part/newsletter_signup/{$link->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected_away(): void
    {
        $owner = $this->makeUser();
        $link = $this->newsletterBlock($owner);

        $this->get("/studio/linkparamform_part/newsletter_signup/{$link->id}")
            ->assertRedirect();
    }

    public function test_unknown_typename_is_rejected_before_view_resolution(): void
    {
        $user = $this->makeUser();

        // $typename selects the Blade template, so an unknown value must
        // 404 rather than reach view() as a caller-supplied path.
        $this->actingAs($user)
            ->get('/studio/linkparamform_part/not_a_real_block/0')
            ->assertNotFound();
    }

    public function test_add_mode_still_renders_an_empty_form(): void
    {
        $user = $this->makeUser();

        // The editor sends "0" when adding a new block; that path must not
        // hit the ownership check at all.
        $this->actingAs($user)
            ->get('/studio/linkparamform_part/newsletter_signup/0')
            ->assertSuccessful();
    }
}
