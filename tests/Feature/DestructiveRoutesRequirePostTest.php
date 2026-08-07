<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laravel's VerifyCsrfToken only validates POST/PUT/PATCH/DELETE, so a
 * state-changing route reachable by GET has no CSRF protection at all --
 * and SESSION_SAME_SITE=lax still sends the session cookie on a top-level
 * GET navigation, so getting an admin to open a link was enough to delete
 * a user, block one, promote one, or start an impersonation session.
 *
 * These pin the methods. Note the framework skips CSRF verification while
 * running tests (VerifyCsrfToken::runningUnitTests), so "GET no longer
 * routes" is the assertion that actually demonstrates the fix.
 */
class DestructiveRoutesRequirePostTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->makeUser(['role' => 'admin']);
    }

    private function linkFor(User $user): Link
    {
        $link = new Link();
        $link->user_id = $user->id;
        $link->title = 'A block';
        $link->link = 'https://example.com';
        $link->button_id = 1;
        $link->type = 'link';
        $link->order = 1;
        $link->save();

        return $link;
    }

    public function test_admin_destructive_routes_reject_get(): void
    {
        $admin = $this->admin();
        $victim = $this->makeUser();
        $link = $this->linkFor($victim);

        $paths = [
            '/admin/delete-user/' . $victim->id,
            '/admin/deleteLink/' . $link->id,
            '/admin/users/block/yes/' . $victim->id,
            '/admin/users/verify/vip/' . $victim->id,
            '/admin/users/verify-mail/yes/' . $victim->id,
            '/admin/site/delavatar',
            '/admin/site/delfavicon',
            '/auth-as/' . $victim->id,
        ];

        foreach ($paths as $path) {
            $this->actingAs($admin)->get($path)->assertStatus(405, "GET $path should not route");
        }

        // ...and the user is still there.
        $this->assertNotNull(User::find($victim->id));
    }

    public function test_user_destructive_routes_reject_get(): void
    {
        $user = $this->makeUser();
        $link = $this->linkFor($user);

        $paths = [
            '/deleteLink/' . $link->id,
            '/upLink/up/' . $link->id,
            '/clearIcon/' . $link->id,
            '/studio/rem-background',
            '/studio/rem-favicon',
            '/studio/page/delprofilepicture',
        ];

        foreach ($paths as $path) {
            $this->actingAs($user)->get($path)->assertStatus(405, "GET $path should not route");
        }

        $this->assertNotNull(Link::find($link->id), 'the block must survive every GET');
    }

    public function test_post_still_deletes_a_block(): void
    {
        $user = $this->makeUser();
        $link = $this->linkFor($user);

        $this->actingAs($user)->post('/deleteLink/' . $link->id);

        $this->assertNull(Link::find($link->id), 'POST must still work');
    }

    public function test_post_still_deletes_a_user(): void
    {
        $admin = $this->admin();
        $victim = $this->makeUser();

        $this->actingAs($admin)->post('/admin/delete-user/' . $victim->id);

        $this->assertNull(User::find($victim->id), 'POST must still work');
    }

    public function test_ownership_is_still_enforced_on_the_post_route(): void
    {
        $owner = $this->makeUser();
        $attacker = $this->makeUser();
        $link = $this->linkFor($owner);

        $this->actingAs($attacker)->post('/deleteLink/' . $link->id)->assertForbidden();

        $this->assertNotNull(Link::find($link->id));
    }

    public function test_studio_editor_renders_delete_actions_as_posts(): void
    {
        $user = $this->makeUser();
        $this->linkFor($user);

        $response = $this->actingAs($user)->get('/studio/edit');

        $response->assertSuccessful();
        // The block row's delete control is now a form posting to the route.
        $response->assertSee('action="' . route('deleteLink', ['id' => Link::where('user_id', $user->id)->value('id')]) . '"', false);
        $response->assertSee('mm-post-action', false);
    }

    public function test_admin_pages_still_render(): void
    {
        $admin = $this->admin();
        $victim = $this->makeUser();
        $this->linkFor($victim);

        // Regression guard: these are the pages whose markup changed.
        $this->actingAs($admin)->get('/admin/users')->assertSuccessful();
        $this->actingAs($admin)->get('/admin/site')->assertSuccessful();
        $this->actingAs($admin)->get('/admin/links/' . $victim->id)->assertSuccessful();
    }
}
