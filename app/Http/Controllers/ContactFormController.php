<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles public submissions from the "contact_form" block.
 *
 * One instance of this controller serves every contact_form block across
 * the whole LinkStack installation. The {id} in the route path is the
 * `links.id` of the specific block instance being submitted to — we look
 * it up to find the destination e-mail and custom subject configured by
 * the page owner.
 */
class ContactFormController extends Controller
{
    public function submit(Request $request, $id)
    {
        $link = Link::find($id);

        // Guard: the link must exist and must actually be a contact_form block.
        if (!$link || $link->type !== 'contact_form') {
            abort(404);
        }

        // Honeypot: if the invisible `website` field is filled, assume bot.
        // Return a success response so the bot thinks it worked — but do
        // not send any mail. Never log this as an error.
        if (filled($request->input('website'))) {
            return back()
                ->with('contact_form_success', (int) $id)
                ->withFragment("contact-form-$id");
        }

        // Timing check: a real visitor takes a few seconds to fill the form;
        // a bot posts instantly. A valid render token that arrived implausibly
        // fast is treated like the honeypot — pretend success, send nothing.
        // A missing/forged token is NOT blocked here (a stale cached page can
        // lack it); the honeypot + per-IP rate limit still cover that case.
        $elapsed = cf_token_elapsed($request->input('cf_ts'));
        if ($elapsed !== null && $elapsed < 3) {
            return back()
                ->with('contact_form_success', (int) $id)
                ->withFragment("contact-form-$id");
        }

        // name and email become the Reply-To pair in ContactFormMail, and
        // this endpoint is public -- so both are attacker-supplied values
        // landing in a mail header. The framework's email rule accepts CRLF
        // on this branch (see mm_header_safe_rule).
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:100', mm_header_safe_rule()],
            'email'   => ['required', 'email', 'max:255', mm_header_safe_rule()],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $params = json_decode($link->type_params ?? '{}', true);
        if (!is_array($params)) {
            $params = [];
        }
        $customSubject = trim((string) ($params['subject'] ?? ''));
        $subject = $customSubject !== ''
            ? $customSubject
            : 'New message from your Mail Minted contact form';

        try {
            Mail::to($link->link)->send(new ContactFormMail($data, $subject, $link));
        } catch (\Throwable $e) {
            Log::error('Contact form send failed', [
                'link_id' => (int) $id,
                'to'      => $link->link,
                'error'   => $e->getMessage(),
            ]);

            return back()
                ->with('contact_form_error', (int) $id)
                ->withFragment("contact-form-$id")
                ->withInput();
        }

        return back()
            ->with('contact_form_success', (int) $id)
            ->withFragment("contact-form-$id");
    }
}
