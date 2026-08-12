<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Per-customer browser-tab icon (favicon): upload/remove endpoints and
 * the white-label swap in the public page's <head>. Files land in
 * assets/img/favicon-img/{uid}_{time}.{ext} and take effect
 * immediately (not part of the draft/publish snapshot).
 */
class UserFaviconTest extends TestCase
{
    use RefreshDatabase;

    /** Users whose uploaded files need purging from the real assets dir. */
    private array $cleanupUserIds = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupUserIds as $id) {
            purge_user_uploads($id);
        }
        parent::tearDown();
    }

    private function makeUserWithCleanup(): User
    {
        $user = $this->makeUser();
        $this->cleanupUserIds[] = $user->id;
        return $user;
    }

    public function test_upload_stores_favicon_and_public_page_uses_it(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)
            ->post('/studio/favicon', [
                'image' => UploadedFile::fake()->image('icon.png', 64, 64),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = findFavicon($user->id);
        $this->assertNotSame('error.error', $stored, 'uploaded favicon not found on disk');
        $this->assertFileExists(base_path('assets/img/favicon-img/' . $stored));

        $this->post('/logout');
        $this->get('/@' . $user->littlelink_name)
            ->assertSuccessful()
            ->assertSee('assets/img/favicon-img/' . $stored, false);
    }

    public function test_new_upload_replaces_the_previous_favicon(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('one.png', 32, 32),
        ]);
        $first = findFavicon($user->id);

        $this->actingAs($user)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('two.jpg', 32, 32),
        ]);
        $second = findFavicon($user->id);

        $this->assertNotSame('error.error', $second);
        $this->assertFileExists(base_path('assets/img/favicon-img/' . $second));
        if ($first !== $second) {
            $this->assertFileDoesNotExist(base_path('assets/img/favicon-img/' . $first));
        }
    }

    public function test_remove_deletes_file_and_page_falls_back_to_site_icon(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $stored = findFavicon($user->id);
        $this->assertNotSame('error.error', $stored);

        // POST: removal is state-changing, so it must carry CSRF.
        $this->actingAs($user)->post('/studio/rem-favicon')->assertRedirect();

        $this->assertSame('error.error', findFavicon($user->id));

        $this->post('/logout');
        $this->get('/@' . $user->littlelink_name)
            ->assertSuccessful()
            ->assertDontSee('assets/img/favicon-img', false);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)
            ->post('/studio/favicon', [
                'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertSame('error.error', findFavicon($user->id));
    }

    public function test_oversized_favicon_is_rejected(): void
    {
        $user = $this->makeUserWithCleanup();

        // 512KB cap: a favicon is a few KB, and the browser refetches it on
        // every page load, so the limit is deliberately tighter than the
        // 2MB allowed for avatars and backgrounds.
        $this->actingAs($user)
            ->post('/studio/favicon', [
                'image' => UploadedFile::fake()->image('huge.png', 512, 512)->size(600),
            ])
            ->assertSessionHasErrors('image');

        $this->assertSame('error.error', findFavicon($user->id));
    }

    public function test_favicon_at_the_size_limit_is_accepted(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)
            ->post('/studio/favicon', [
                'image' => UploadedFile::fake()->image('ok.png', 256, 256)->size(500),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotSame('error.error', findFavicon($user->id));
    }

    public function test_guests_cannot_upload_a_favicon(): void
    {
        $this->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect('/login');
    }

    public function test_studio_chrome_uses_the_signed_in_users_favicon(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $stored = findFavicon($user->id);
        $this->assertNotSame('error.error', $stored);

        // White-label extends to the editor itself, not just the public page.
        $this->actingAs($user)
            ->get('/studio/edit')
            ->assertSuccessful()
            ->assertSee('assets/img/favicon-img/' . $stored, false);
    }

    public function test_studio_chrome_falls_back_to_the_site_icon(): void
    {
        $user = $this->makeUserWithCleanup();

        $this->actingAs($user)
            ->get('/studio/edit')
            ->assertSuccessful()
            ->assertDontSee('assets/img/favicon-img', false);
    }

    public function test_studio_chrome_does_not_leak_another_users_favicon(): void
    {
        $owner = $this->makeUserWithCleanup();
        $other = $this->makeUserWithCleanup();

        $this->actingAs($owner)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $this->assertNotSame('error.error', findFavicon($owner->id));

        $this->actingAs($other)
            ->get('/studio/edit')
            ->assertSuccessful()
            ->assertDontSee('assets/img/favicon-img', false);
    }

    public function test_admin_panel_uses_the_signed_in_admins_favicon(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->cleanupUserIds[] = $admin->id;

        $this->actingAs($admin)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $stored = findFavicon($admin->id);
        $this->assertNotSame('error.error', $stored);

        // panel/* and admin/* share layouts.sidebar with the studio.
        $this->actingAs($admin)
            ->get('/admin/config')
            ->assertSuccessful()
            ->assertSee('assets/img/favicon-img/' . $stored, false);
    }

    public function test_favicon_only_affects_its_owners_page(): void
    {
        $owner = $this->makeUserWithCleanup();
        $other = $this->makeUserWithCleanup();

        $this->actingAs($owner)->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]);
        $stored = findFavicon($owner->id);
        $this->assertNotSame('error.error', $stored);

        $this->post('/logout');
        $this->get('/@' . $other->littlelink_name)
            ->assertSuccessful()
            ->assertDontSee('assets/img/favicon-img', false);
    }
}
