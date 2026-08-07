<?php

/**
 * Handles the logic for "contact_form" link type.
 *
 * Storage mapping (all handled by UserController::saveLink at
 * app/Http/Controllers/UserController.php:230-292):
 *
 *   - title   → links.title        (section heading)
 *   - link    → links.link         (destination e-mail address)
 *   - subject → merged into links.type_params JSON alongside the
 *               LinkType meta (custom_html, ignore_container,
 *               include_libraries) that saveLink appends automatically.
 *               LinkTypeViewController::getParamForm() later decodes this
 *               JSON and re-exposes `$subject` to form.blade.php on edit.
 *   - button_id is intentionally omitted; saveLink defaults it to 1.
 *
 * @param \Illuminate\Http\Request $request
 * @param mixed $linkType
 * @return array
 */
function handleLinkType($request, $linkType) {

    $rules = [
        'title'   => ['required', 'string', 'max:100'],
        // Destination address (Mail::to) and subject line both end up in
        // mail headers, and the framework's email rule accepts CRLF on this
        // branch -- see mm_header_safe_rule().
        'link'    => ['required', 'email', 'max:255', mm_header_safe_rule()],
        'subject' => ['nullable', 'string', 'max:150', mm_header_safe_rule()],
    ];

    $linkData = [
        'title'     => strip_tags((string) $request->input('title')),
        'link'      => strtolower(trim((string) $request->input('link'))),
        'subject'   => trim((string) $request->input('subject', '')),
        // Per-instance "Start collapsed" toggle — set by the shared
        // resources/views/studio/partials/block-collapsed-toggle.blade.php
        // partial in form.blade.php. Wrapped in <details> by
        // resources/views/linkstack/elements/buttons.blade.php.
        'collapsed' => (bool) $request->input('collapsed'),
    ];

    return ['rules' => $rules, 'linkData' => $linkData];
}
