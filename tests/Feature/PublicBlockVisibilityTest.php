<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /going/{id} and /vcard/{id} reach a block directly by id rather than
 * through a page render, and neither applied the visibility rules that
 * UserController::maybePublishedView applies. So a suspended account's
 * data, and blocks that exist only in an unpublished draft, stayed
 * publicly reachable to anyone who knew the id -- and ids appear in public
 * page markup.
 *
 * vCard blocks make that concrete: they carry home address, home/cell/work
 * phone, and personal and work e-mail.
 */
class PublicBlockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function vcardPayload(): string
    {
        return json_encode([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'middle_name' => '',
            'prefix' => '', 'suffix' => '', 'organization' => 'Analytical Co',
            'vtitle' => 'Engineer', 'role' => 'Eng',
            'email' => 'ada@example.test', 'work_email' => 'work@example.test',
            'work_url' => 'https://example.test',
            'home_phone' => '555-0100', 'work_phone' => '555-0101', 'cell_phone' => '555-0102',
            'home_address_street' => '12 Secret Lane', 'home_address_city' => 'London',
            'home_address_state' => '', 'home_address_zip' => 'E1', 'home_address_country' => 'UK',
            'work_address_street' => '1 Work Rd', 'work_address_city' => 'London',
            'work_address_state' => '', 'work_address_zip' => 'E2', 'work_address_country' => 'UK',
        ]);
    }

    private function block(User $owner, string $type = 'link'): Link
    {
        $link = new Link();
        $link->user_id = $owner->id;
        $link->title = 'A block';
        $link->link = $type === 'vcard' ? $this->vcardPayload() : 'https://example.com';
        $link->button_id = 1;
        $link->type = $type;
        $link->order = 1;
        $link->save();

        return $link;
    }

    /** Publish exactly the given blocks, leaving anything else draft-only. */
    private function publishOnly(User $owner, array $links): void
    {
        User::where('id', $owner->id)->update([
            'published_snapshot' => json_encode([
                'user' => ['id' => $owner->id],
                'blocks' => array_map(fn ($l) => ['id' => $l->id], $links),
            ]),
        ]);
    }

    public function test_published_vcard_is_served(): void
    {
        $owner = $this->makeUser();
        $card = $this->block($owner, 'vcard');
        $this->publishOnly($owner, [$card]);

        $this->get('/vcard/' . $card->id)->assertSuccessful();
    }

    public function test_draft_only_vcard_is_not_served(): void
    {
        $owner = $this->makeUser();
        $published = $this->block($owner);
        $draftCard = $this->block($owner, 'vcard');
        $this->publishOnly($owner, [$published]); // draftCard deliberately omitted

        $response = $this->get('/vcard/' . $draftCard->id);

        $response->assertNotFound();
        $response->assertDontSee('12 Secret Lane', false);
    }

    public function test_suspended_owners_vcard_is_not_served(): void
    {
        $owner = $this->makeUser();
        $card = $this->block($owner, 'vcard');
        $this->publishOnly($owner, [$card]);
        User::where('id', $owner->id)->update(['block' => 'yes']);

        $response = $this->get('/vcard/' . $card->id);

        $response->assertNotFound();
        $response->assertDontSee('12 Secret Lane', false);
    }

    public function test_never_published_owner_still_serves_their_live_blocks(): void
    {
        // Matches maybePublishedView: an empty snapshot renders live, so
        // the gate must not hide a page that has simply never published.
        $owner = $this->makeUser();
        $card = $this->block($owner, 'vcard');

        $this->get('/vcard/' . $card->id)->assertSuccessful();
    }

    public function test_vcard_rejects_a_non_vcard_block(): void
    {
        $owner = $this->makeUser();
        $link = $this->block($owner, 'link');

        // Used to read a plain URL as vCard JSON and emit undefined-index
        // errors, which is a debug-page leak when APP_DEBUG is on.
        $this->get('/vcard/' . $link->id)->assertNotFound();
    }

    public function test_click_redirect_respects_suspension(): void
    {
        $owner = $this->makeUser();
        $link = $this->block($owner);
        $this->publishOnly($owner, [$link]);
        User::where('id', $owner->id)->update(['block' => 'yes']);

        $this->get('/going/' . $link->id)->assertNotFound();
    }

    public function test_click_redirect_still_works_for_a_published_block(): void
    {
        $owner = $this->makeUser();
        $link = $this->block($owner);
        $this->publishOnly($owner, [$link]);

        $this->get('/going/' . $link->id)->assertRedirect('https://example.com');
        $this->assertSame(1, Link::find($link->id)->click_number);
    }

    public function test_click_redirect_hides_draft_only_blocks(): void
    {
        $owner = $this->makeUser();
        $published = $this->block($owner);
        $draft = $this->block($owner);
        $this->publishOnly($owner, [$published]);

        $this->get('/going/' . $draft->id)->assertNotFound();
        $this->assertSame(0, Link::find($draft->id)->click_number);
    }
}
