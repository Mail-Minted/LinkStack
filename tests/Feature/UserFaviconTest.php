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

        $this->actingAs($user)->get('/studio/rem-favicon')->assertRedirect();

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

    public function test_guests_cannot_upload_a_favicon(): void
    {
        $this->post('/studio/favicon', [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect('/login');
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
