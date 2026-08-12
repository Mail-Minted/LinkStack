<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Services\PageVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Instant-live + version history: the public bio page always renders
 * the live DB rows; page_versions holds restorable point-in-time
 * versions (captured on editor load, and before every restore).
 */
class PageVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function makePageWithHeading(string $title = 'Version One'): array
    {
        $user = $this->makeUser();

        $block = new Link();
        $block->user_id = $user->id;
        $block->button_id = 1;
        $block->type = 'heading';
        $block->title = $title;
        $block->link = '';
        $block->order = 0;
        $block->type_params = json_encode(['custom_html' => true]);
        $block->save();

        return [$user, $block];
    }

    public function test_public_page_shows_edits_immediately(): void
    {
        [$user, $block] = $this->makePageWithHeading('Version One');
        $block->update(['title' => 'Version Two']);

        $public = $this->get('/@' . $user->littlelink_name);
        $public->assertSuccessful();
        $public->assertSee('Version Two');
        $public->assertDontSee('Version One');
    }

    public function test_editor_load_captures_a_version(): void
    {
        [$user] = $this->makePageWithHeading();

        $response = $this->actingAs($user)->get('/studio/edit');
        $response->assertSuccessful();
        // The publish bar is gone; the instant-live bar + History menu render.
        $response->assertSee('go live right away');
        $response->assertSee('History');
        $response->assertDontSee('unpublished changes');
        $response->assertSee('/studio/history/', false);

        $this->assertSame(1, DB::table('page_versions')->where('user_id', $user->id)->count());
    }

    public function test_editor_load_skips_capture_when_unchanged(): void
    {
        [$user] = $this->makePageWithHeading();

        $this->actingAs($user)->get('/studio/edit');
        $this->actingAs($user)->get('/studio/edit');

        $this->assertSame(
            1,
            DB::table('page_versions')->where('user_id', $user->id)->count(),
            'a reload with no page changes must not stack identical versions'
        );
    }

    public function test_restore_round_trips_blocks_and_fields(): void
    {
        [$user, $block] = $this->makePageWithHeading('Version One');
        $originalName = $user->name;
        $versionId = PageVersions::capture($user->id, 'edit');

        // Edit the page: retitle the block, add another, rename the user.
        $block->update(['title' => 'Version Two']);
        $extra = new Link();
        $extra->user_id = $user->id;
        $extra->button_id = 1;
        $extra->type = 'heading';
        $extra->title = 'Added Later';
        $extra->link = '';
        $extra->order = 1;
        $extra->save();
        $user->update(['name' => 'Renamed User']);

        $this->actingAs($user)
            ->post('/studio/history/' . $versionId . '/restore')
            ->assertRedirect('/studio/edit');

        $this->assertSame('Version One', Link::find($block->id)->title);
        $this->assertNull(Link::find($extra->id), 'blocks added after the version must be removed by restore');
        $this->assertSame($originalName, $user->fresh()->name);
    }

    public function test_restore_captures_the_current_state_first(): void
    {
        [$user, $block] = $this->makePageWithHeading('Version One');
        $versionId = PageVersions::capture($user->id, 'edit');
        $block->update(['title' => 'Version Two']);

        $this->actingAs($user)->post('/studio/history/' . $versionId . '/restore');

        $safety = DB::table('page_versions')
            ->where('user_id', $user->id)->where('cause', 'before-restore')->first();
        $this->assertNotNull($safety, 'restore must record the pre-restore state so it can be undone');
        $this->assertStringContainsString('Version Two', $safety->snapshot);
    }

    public function test_restore_rejects_another_users_version(): void
    {
        [$owner] = $this->makePageWithHeading('Owner Page');
        $versionId = PageVersions::capture($owner->id, 'edit');

        $stranger = $this->makeUser();
        $this->actingAs($stranger)
            ->post('/studio/history/' . $versionId . '/restore')
            ->assertNotFound();

        $this->assertSame(
            1,
            DB::table('page_versions')->where('user_id', $owner->id)->count(),
            "a stranger's restore attempt must not touch the owner's history"
        );
    }

    public function test_history_is_pruned_to_the_keep_limit(): void
    {
        [$user, $block] = $this->makePageWithHeading();

        for ($i = 0; $i < PageVersions::KEEP + 5; $i++) {
            $block->update(['title' => 'Title ' . $i]);
            PageVersions::capture($user->id, 'edit');
        }

        $this->assertSame(
            PageVersions::KEEP,
            DB::table('page_versions')->where('user_id', $user->id)->count()
        );
    }
}
