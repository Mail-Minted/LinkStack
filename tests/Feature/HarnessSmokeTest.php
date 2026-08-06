<?php

namespace Tests\Feature;

use App\Models\Button;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the test harness itself: fresh in-memory DB, migrations,
 * seeders, auth, and a real HTTP round-trip. If this file fails,
 * fix the harness before trusting anything else.
 */
class HarnessSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_migrates_and_seeds(): void
    {
        $this->assertGreaterThan(0, Button::count(), 'ButtonSeeder should populate buttons');
    }

    public function test_home_page_responds(): void
    {
        $this->get('/')->assertSuccessful();
    }

    public function test_make_user_honours_an_explicit_id(): void
    {
        // User::creating assigns a random 6-digit id — but it used to do
        // so even when the caller had already chosen one, so
        // makeUser(['id' => 990100]) silently produced id 1. Tests that
        // touch the SHARED assets/img dirs pick high ids precisely to
        // stay clear of real accounts' files; that guarantee is only
        // worth anything if the id survives the insert.
        $user = $this->makeUser(['id' => 990001]);

        $this->assertSame(990001, $user->id);
        $this->assertDatabaseHas('users', ['id' => 990001]);
    }

    public function test_authenticated_studio_loads(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/studio/edit')
            ->assertSuccessful()
            ->assertSee('Appearance');
    }
}
