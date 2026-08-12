<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * saveLink invariants for the block editor — pins the July 2026 fixes:
 * silent validation failures (blocks "not showing up"), stripe field
 * rules (tip jar must not demand a price; option labels optional), the
 * smallest-unit currency conversion, and pristine blocks never getting
 * styling frozen onto them by an ordinary content save.
 */
class BlockSaveTest extends TestCase
{
    use RefreshDatabase;

    private function stripePayload(array $overrides = []): array
    {
        return array_merge([
            'linkid'              => '',
            'typename'            => 'stripe_payment',
            'title'               => 'Buy me a coffee',
            'link'                => 'Buy now',
            'mode'                => 'fixed_price',
            'currency'            => 'usd',
            'product_description' => 'One coffee',
            'option_1_amount'     => '5.00',
        ], $overrides);
    }

    public function test_stripe_single_price_saves_with_options_array(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload());

        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $this->assertNotNull($block, 'a valid stripe block must be created');

        $tp = json_decode($block->type_params, true);
        $this->assertSame('fixed_price', $tp['mode']);
        $this->assertSame([['label' => '', 'amount_cents' => 500]], $tp['options']);
        $this->assertSame(500, $tp['amount_cents'], 'legacy mirror of options[0]');
    }

    public function test_stripe_missing_description_fails_loudly_not_silently(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->post(
            '/studio/edit-link',
            $this->stripePayload(['product_description' => '', 'embed' => '1'])
        );

        // The old bug: back() resolved to /dashboard and the error was
        // never rendered — the block just "didn't show up".
        $response->assertRedirect(url('studio/add-link?embed=1'));
        $response->assertSessionHasErrors('product_description');
        $this->assertSame(0, Link::where('user_id', $user->id)->count(), 'no block may be created on validation failure');
    }

    public function test_stripe_fixed_price_requires_option_1_amount(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/studio/edit-link', $this->stripePayload(['option_1_amount' => '']))
            ->assertSessionHasErrors('option_1_amount');

        $this->assertSame(0, Link::where('user_id', $user->id)->count());
    }

    public function test_stripe_tip_jar_requires_no_price(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'mode'            => 'tip_jar',
            'option_1_amount' => '',
        ]));

        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $this->assertNotNull($block, 'a tip jar must save without any preset price');

        $tp = json_decode($block->type_params, true);
        $this->assertSame('tip_jar', $tp['mode']);
        $this->assertSame(100, $tp['min_amount_cents'], 'minimum defaults to 1.00');
        $this->assertNull($tp['suggested_amount_cents']);
    }

    public function test_stripe_option_labels_are_optional(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'option_1_amount' => '5.00',
            'option_2_label'  => 'Deluxe',
            'option_2_amount' => '10.00',
        ]));

        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $tp = json_decode($block->type_params, true);
        $this->assertCount(2, $tp['options']);
        $this->assertSame('', $tp['options'][0]['label'], 'label may be blank');
        $this->assertSame('Deluxe', $tp['options'][1]['label']);
    }

    public function test_zero_decimal_currency_stores_whole_units(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'currency'        => 'jpy',
            'option_1_amount' => '500',
        ]));

        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $tp = json_decode($block->type_params, true);
        $this->assertSame(500, $tp['options'][0]['amount_cents'], 'JPY has no minor unit — 500 yen is 500, not 50000');
    }

    public function test_pristine_block_stays_pristine_through_content_edits(): void
    {
        $user = $this->makeUser();

        // Create.
        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'custom_css'  => '',
            'custom_icon' => '',
        ]));
        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $this->assertSame('', (string) $block->custom_css);

        // Edit only the title — the block must keep following the theme.
        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'linkid'      => (string) $block->id,
            'title'       => 'New title',
            'custom_css'  => '',
            'custom_icon' => '',
        ]));

        $block->refresh();
        $this->assertSame('New title', $block->title);
        $this->assertSame('', (string) $block->custom_css, 'a content edit must never freeze styling onto a pristine block');
    }

    public function test_xhr_save_returns_json_and_creates_block(): void
    {
        $user = $this->makeUser();

        // The Blocks tab's fetch-based editor panel saves via XHR and
        // expects a JSON OK instead of the full-page redirect.
        $response = $this->actingAs($user)->postJson('/studio/edit-link', $this->stripePayload());

        $response->assertOk()->assertJson(['message' => 'Link added']);
        $this->assertNotNull(
            Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first(),
            'an XHR save must persist the block like the form save does'
        );
    }

    public function test_xhr_validation_failure_returns_422_with_errors(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(
            '/studio/edit-link',
            $this->stripePayload(['product_description' => ''])
        );

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['product_description']]);
        $this->assertSame(0, Link::where('user_id', $user->id)->count());
    }

    public function test_diverged_block_keeps_custom_css_on_content_edit(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'custom_css' => 'background: #123456;',
        ]));
        $block = Link::where('user_id', $user->id)->where('type', 'stripe_payment')->first();
        $this->assertSame('background: #123456;', $block->custom_css);

        $this->actingAs($user)->post('/studio/edit-link', $this->stripePayload([
            'linkid'     => (string) $block->id,
            'title'      => 'Renamed',
            'custom_css' => 'background: #123456;',
        ]));

        $block->refresh();
        $this->assertSame('Renamed', $block->title);
        $this->assertSame('background: #123456;', $block->custom_css);
    }
}
