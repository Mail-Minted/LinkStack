<?php

namespace Tests\Feature;

use App\Mail\ContactFormMail;
use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Contact-form submit endpoint: spam protection (honeypot + signed
 * timing token + per-IP throttle) and the happy path. Bots get a fake
 * success so they don't adapt; humans get real mail.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function makeContactBlock(): Link
    {
        $owner = $this->makeUser();

        $block = new Link();
        $block->user_id = $owner->id;
        $block->button_id = 1;
        $block->type = 'contact_form';
        $block->title = 'Get in touch';
        $block->link = 'owner-inbox@example.test'; // recipient lives on links.link
        $block->order = 0;
        $block->type_params = json_encode(['custom_html' => true]);
        $block->save();

        return $block;
    }

    /** A cf_ts token minted $secondsAgo in the past (same HMAC the app uses). */
    private function agedToken(int $secondsAgo): string
    {
        $ts = (string) (time() - $secondsAgo);

        return $ts . '.' . hash_hmac('sha256', $ts, (string) config('app.key'));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'    => 'Jane Visitor',
            'email'   => 'jane@example.test',
            'message' => 'Hello there, I would like to hire you.',
            'website' => '',                    // honeypot stays empty
            'cf_ts'   => $this->agedToken(30),  // filled the form like a human
        ], $overrides);
    }

    public function test_valid_submission_sends_mail_to_the_block_owner(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload())
            ->assertSessionHas('contact_form_success', $block->id);

        Mail::assertSent(ContactFormMail::class, function ($mail) {
            return $mail->hasTo('owner-inbox@example.test');
        });
    }

    public function test_filled_honeypot_sends_nothing_but_fakes_success(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'website' => 'https://spam.example',
        ]))->assertSessionHas('contact_form_success', $block->id);

        Mail::assertNothingSent();
    }

    public function test_implausibly_fast_submission_sends_nothing(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'cf_ts' => $this->agedToken(1), // bot speed
        ]))->assertSessionHas('contact_form_success', $block->id);

        Mail::assertNothingSent();
    }

    public function test_invalid_email_is_rejected(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'email' => 'not-an-email',
        ]))->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_submit_rejects_non_contact_blocks(): void
    {
        Mail::fake();
        $owner = $this->makeUser();
        $block = new Link();
        $block->user_id = $owner->id;
        $block->button_id = 1;
        $block->type = 'heading';
        $block->title = 'Not a form';
        $block->link = 'x@example.test';
        $block->order = 0;
        $block->save();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload())
            ->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_per_ip_throttle_kicks_in_after_five_submissions(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        foreach (range(1, 5) as $i) {
            $this->post("/contact-form/{$block->id}/submit", $this->validPayload())
                ->assertSessionHas('contact_form_success', $block->id);
        }

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload())
            ->assertStatus(429);
    }

    /**
     * Defense in depth, not a live fix.
     *
     * name and email become the Reply-To pair, and this endpoint is public.
     * Laravel's advisory GHSA-5vg9-5847-vvmq (CRLF in the default email
     * rule) has no fix below 12.60, so the validation carries it here --
     * but note the exposure was already closed twice over: the installed
     * email rule rejects CRLF, and Symfony strips CR/LF from a display name
     * and quotes it rather than emitting a second header line. This pins
     * the input bound so a future mailer or transport change cannot quietly
     * reopen it.
     */
    public function test_control_characters_are_rejected_in_the_reply_to_pair(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'name' => "Ada\r\nBcc: attacker@evil.test",
        ]))->assertSessionHasErrors('name');

        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'email' => "ada@example.test\r\nBcc: attacker@evil.test",
        ]))->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_an_ordinary_submission_is_unaffected(): void
    {
        Mail::fake();
        $block = $this->makeContactBlock();

        // Names with punctuation and non-ASCII must still get through.
        $this->post("/contact-form/{$block->id}/submit", $this->validPayload([
            'name' => "Ada O'Brien-Lovelace \u{00e9}",
        ]))->assertSessionHasNoErrors();

        Mail::assertSent(\App\Mail\ContactFormMail::class);
    }
}
