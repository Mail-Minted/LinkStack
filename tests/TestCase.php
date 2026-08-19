<?php

namespace Tests;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Run DatabaseSeeder (pages + buttons) after migrating the fresh
     * in-memory DB — block saves reference button rows, and several
     * pages routes 404 without the pages table content.
     */
    protected $seed = true;

    /**
     * Make a bare customer account the way the SSO provisioner shapes
     * them: unique handle, a theme, no styling overrides. Most feature
     * tests start here.
     */
    /**
     * Give a user one block, so their public page renders as a built
     * page. A page with no blocks and no edit history now serves the
     * "Coming soon" holding page (UserController@pageIsUntouched), so
     * any test asserting on real page output needs actual content.
     */
    protected function makeBlock(User $user, string $title = 'Test block'): Link
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

    protected function makeUser(array $overrides = []): User
    {
        static $n = 0;
        $n++;

        $user = new User();
        $user->name = $overrides['name'] ?? "Test User {$n}";
        $user->email = $overrides['email'] ?? "user{$n}_" . uniqid() . '@example.test';
        $user->password = bcrypt('secret-password');
        $user->littlelink_name = $overrides['littlelink_name'] ?? "test-user-{$n}-" . uniqid();
        $user->role = $overrides['role'] ?? 'user';
        $user->theme = $overrides['theme'] ?? 'default';
        $user->email_verified_at = now();

        foreach ($overrides as $key => $value) {
            $user->{$key} = $value;
        }

        $user->save();

        return $user;
    }
}
