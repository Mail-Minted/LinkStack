<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mail Minted admin API — DELETE /api/admin/users/{id}
 * (routes/api-admin.php). Deleting a user must also remove their
 * links and social_accounts rows: links.user_id is a FOREIGN KEY
 * without ON DELETE CASCADE, so deleting the users row alone throws
 * an integrity constraint violation (seen in production deleting a
 * customer with 12 links).
 */
class AdminApiUserDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . env('MAILMINTED_ADMIN_API_TOKEN')];
    }

    private function makeLink(User $user): Link
    {
        $link = new Link();
        $link->link = 'https://example.test/somewhere';
        $link->title = 'Somewhere';
        $link->user_id = $user->id;
        $link->button_id = 1;
        $link->save();

        return $link;
    }

    public function test_delete_user_who_has_links_succeeds(): void
    {
        // First user created gets id=1, which the endpoint refuses to
        // delete (installer-admin guard) — target the second user.
        $this->makeUser();
        $user = $this->makeUser();
        for ($i = 0; $i < 3; $i++) {
            $this->makeLink($user);
        }
        SocialAccount::create([
            'user_id' => $user->id,
            'provider_name' => 'github',
            'provider_id' => 'gh-12345',
        ]);

        $this->deleteJson('/api/admin/users/' . $user->id, [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'deleted' => true]);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('links', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
    }

    public function test_delete_user_without_links_succeeds(): void
    {
        $this->makeUser();
        $user = $this->makeUser();

        $this->deleteJson('/api/admin/users/' . $user->id, [], $this->authHeaders())
            ->assertOk()
            ->assertJson(['user_id' => $user->id, 'deleted' => true]);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_delete_refuses_admin_user_id_1(): void
    {
        $admin = $this->makeUser();
        $this->assertSame(1, $admin->id);

        $this->deleteJson('/api/admin/users/1', [], $this->authHeaders())
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => 1]);
    }

    public function test_delete_requires_bearer_token(): void
    {
        $this->makeUser();
        $user = $this->makeUser();
        $this->makeLink($user);

        $this->deleteJson('/api/admin/users/' . $user->id)->assertStatus(401);
        $this->deleteJson('/api/admin/users/' . $user->id, [], [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('links', ['user_id' => $user->id]);
    }

    public function test_delete_unknown_user_returns_404(): void
    {
        $this->deleteJson('/api/admin/users/86753090000', [], $this->authHeaders())
            ->assertStatus(404);
    }
}
