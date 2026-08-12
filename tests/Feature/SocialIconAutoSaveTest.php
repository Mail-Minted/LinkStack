<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Social tab auto-saves its URL inputs via XHR (mmAutoSaveForm) —
 * editIcons must answer JSON for those saves while keeping the
 * redirect-with-flash behaviour for plain form posts.
 */
class SocialIconAutoSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_xhr_save_returns_json_and_creates_icon(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/edit-icons', ['instagram' => 'myhandle']);

        $response->assertOk()->assertJson(['message' => 'Social icons updated.']);
        $icon = Link::where('user_id', $user->id)->where('title', 'instagram')->where('button_id', 94)->first();
        $this->assertNotNull($icon);
        $this->assertSame('https://instagram.com/myhandle', $icon->link, 'bare handles are normalized to full URLs');
    }

    public function test_xhr_blank_value_deletes_existing_icon(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->postJson('/edit-icons', ['instagram' => 'myhandle']);

        $this->actingAs($user)->postJson('/edit-icons', ['instagram' => ''])->assertOk();

        $this->assertSame(
            0,
            Link::where('user_id', $user->id)->where('title', 'instagram')->where('button_id', 94)->count(),
            'a blanked field must delete the icon row so the public page stops rendering it'
        );
    }

    public function test_plain_form_post_still_redirects_with_flash(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/edit-icons', ['instagram' => 'myhandle'])
            ->assertRedirect('/studio/edit#social')
            ->assertSessionHas('success');
    }
}
