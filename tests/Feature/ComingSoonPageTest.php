<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use App\Services\PageVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Holding page for bio pages that have never been built.
 *
 * Mail Minted provisions a LinkStack user during checkout, so a
 * customer's domain starts resolving minutes after purchase — before
 * they have touched the editor. Without a holding page the first thing
 * their domain ever serves is a blank stranger's profile.
 *
 * The trigger is "no blocks AND no page_versions history", not "no
 * blocks": a customer who deliberately empties their page must not be
 * trapped behind a holding page they cannot remove.
 */
class ComingSoonPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeBioUser(string $domain): User
    {
        return $this->makeUser([
            'name' => 'BioOwner ' . str_replace('.', '-', $domain),
            'littlelink_name' => 'bio-' . str_replace('.', '-', $domain),
            'custom_domain' => $domain,
            'block' => 'no',
        ]);
    }

    private function addBlock(User $user, string $title = 'My link'): Link
    {
        $block = new Link();
        $block->user_id = $user->id;
        $block->button_id = 1;
        $block->type = 'heading';
        $block->title = $title;
        $block->link = '';
        $block->order = 0;
        $block->type_params = json_encode(['custom_html' => true]);
        $block->save();

        return $block;
    }

    public function test_untouched_page_serves_the_holding_page(): void
    {
        $user = $this->makeBioUser('untouched.example');

        $response = $this->get('http://untouched.example/');

        $response->assertOk();
        $response->assertSee('Coming soon');
        $response->assertSee('untouched.example');
        // The auto-generated profile must not leak onto the customer's
        // domain before they have set anything up.
        $response->assertDontSee($user->name);
    }

    public function test_holding_page_is_not_indexable(): void
    {
        $this->makeBioUser('noindex.example');

        $response = $this->get('http://noindex.example/');

        $response->assertSee('noindex, nofollow', false);
    }

    public function test_www_host_also_serves_the_holding_page(): void
    {
        $this->makeBioUser('wwwholding.example');

        $response = $this->get('http://www.wwwholding.example/');

        $response->assertOk();
        $response->assertSee('Coming soon');
    }

    public function test_page_with_a_block_renders_normally(): void
    {
        $user = $this->makeBioUser('built.example');
        $this->addBlock($user, 'My first link');

        $response = $this->get('http://built.example/');

        $response->assertOk();
        $response->assertSee($user->name);
        $response->assertDontSee('Coming soon');
    }

    public function test_emptied_page_with_edit_history_does_not_revert_to_holding(): void
    {
        $user = $this->makeBioUser('emptied.example');
        $block = $this->addBlock($user, 'Temporary link');

        // Customer opened the editor (captures a restore point) and then
        // deleted every block. They are back to zero blocks, but this is
        // a built page they chose to empty — not an untouched one.
        PageVersions::capture($user->id, 'edit');
        $block->delete();

        $response = $this->get('http://emptied.example/');

        $response->assertOk();
        $response->assertDontSee('Coming soon');
    }

    public function test_opening_the_editor_clears_the_holding_page(): void
    {
        // The invariant that makes this design safe: the customer does
        // not have to add a block to escape the holding page. Merely
        // opening the editor captures a restore point, which is what
        // marks the page as touched. Without this, a customer who set
        // only a title or a favicon would still be serving "Coming
        // soon" on their own domain with no way to tell why.
        $user = $this->makeBioUser('opened-editor.example');

        $this->get('http://opened-editor.example/')->assertSee('Coming soon');

        $this->actingAs($user)->get(route('showEditor'))->assertOk();

        $this->get('http://opened-editor.example/')->assertDontSee('Coming soon');
    }

    public function test_handle_url_follows_the_same_rule(): void
    {
        $untouched = $this->makeBioUser('handle-untouched.example');
        $built = $this->makeBioUser('handle-built.example');
        $this->addBlock($built);

        $this->get('/@' . $untouched->littlelink_name)->assertSee('Coming soon');
        $this->get('/@' . $built->littlelink_name)->assertDontSee('Coming soon');
    }
}
