<?php

namespace Tests\Feature;

use App\Models\UserData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browser tab <title> on the public bio page. Upstream LinkStack
 * appended "🔗 {APP_NAME}" to every customer's tab; white-labelling
 * means the display name by default, with a per-customer override
 * editable from Studio > Basics.
 */
class PageTitleTest extends TestCase
{
    use RefreshDatabase;

    private function titleOf(string $html): string
    {
        preg_match('/<title>(.*?)<\/title>/s', $html, $m);
        return trim($m[1] ?? '');
    }

    public function test_default_title_is_just_the_display_name(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);

        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();

        $this->assertSame('Respected Path', $this->titleOf($html));
    }

    public function test_default_title_carries_no_platform_branding(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);

        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();
        $title = $this->titleOf($html);

        $this->assertStringNotContainsString('🔗', $title);
        $this->assertStringNotContainsString(config('app.name'), $title);
    }

    public function test_custom_tab_title_overrides_the_display_name(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);

        $this->actingAs($user)->post('/studio/page', [
            'name'      => 'Respected Path',
            'tabTitle'  => 'Respected Path — Trail Guides',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');
        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();

        $this->assertSame('Respected Path — Trail Guides', $this->titleOf($html));
    }

    public function test_blank_tab_title_clears_the_override(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);
        UserData::saveData($user->id, 'tab-title', 'Old Title');

        $this->actingAs($user)->post('/studio/page', [
            'name'     => 'Respected Path',
            'tabTitle' => '',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');
        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();

        $this->assertSame('Respected Path', $this->titleOf($html));
    }

    public function test_tab_title_is_escaped_not_injected(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);

        $this->actingAs($user)->post('/studio/page', [
            'name'     => 'Respected Path',
            'tabTitle' => '</title><script>alert(1)</script>',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');
        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_overlong_tab_title_is_rejected(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);

        $this->actingAs($user)->post('/studio/page', [
            'name'     => 'Respected Path',
            'tabTitle' => str_repeat('a', 61),
        ])->assertSessionHasErrors('tabTitle');
    }

    public function test_a_form_without_the_field_does_not_clear_it(): void
    {
        $user = $this->makeUser(['name' => 'Respected Path']);
        UserData::saveData($user->id, 'tab-title', 'Kept Title');

        // Toggle-only submits (share button etc.) post to the same route.
        $this->actingAs($user)->post('/studio/page', [
            'name' => 'Respected Path',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');
        $html = $this->get('/@' . $user->littlelink_name)->assertSuccessful()->getContent();

        $this->assertSame('Kept Title', $this->titleOf($html));
    }

    public function test_tab_title_does_not_leak_between_users(): void
    {
        $owner = $this->makeUser(['name' => 'Owner Page']);
        $other = $this->makeUser(['name' => 'Other Page']);
        UserData::saveData($owner->id, 'tab-title', 'Owner Custom Title');

        $html = $this->get('/@' . $other->littlelink_name)->assertSuccessful()->getContent();

        $this->assertSame('Other Page', $this->titleOf($html));
    }
}
