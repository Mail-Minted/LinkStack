@props(['action', 'formClass' => '', 'formStyle' => ''])

{{--
    A destructive action submitted as a real POST carrying a CSRF token.

    Laravel's VerifyCsrfToken only validates POST/PUT/PATCH/DELETE, so a
    state-changing route reachable by GET has no CSRF protection at all --
    and SESSION_SAME_SITE=lax still sends the session cookie on a top-level
    GET navigation, so getting an admin to open a link was enough to fire
    one. These call sites used to be plain <a href> links.

    Attributes passed in (class, data-confirm, title, aria-label, tooltip
    data-*) land on the button, so call sites keep their existing styling
    and the sidebar layout's delegated data-confirm handler keeps working
    -- it re-clicks the element after confirming, which submits the form.

    Add the "mm-post-bare" class where the original looked like a link
    rather than a button; the chrome reset is applied inline so no
    stylesheet or <style> tag is needed (an earlier @once version emitted
    one inside a <td>, which is invalid placement).

    form-class / form-style land on the wrapper, for call sites where the
    old <a> carried layout (float, margins) belonging on the box rather
    than on the button inside it.
--}}
@php
    $mmBareReset = 'background:none;border:0;padding:0;margin:0;font:inherit;'
                 . 'color:inherit;cursor:pointer;text-align:inherit;line-height:inherit;';

    // inline-block + middle so the button lines up with sibling <a class="btn">
    // controls instead of sitting a couple of pixels low on the text baseline.
    $mmFormStyle = 'display:inline-block;vertical-align:middle;' . $formStyle;

    $mmIsBare = str_contains((string) $attributes->get('class', ''), 'mm-post-bare');
    $mmButtonStyle = trim(($mmIsBare ? $mmBareReset : '') . (string) $attributes->get('style', ''));
@endphp
<form method="POST" action="{{ $action }}"
      {{-- no Bootstrap d-inline here: its display rule is !important and
           would beat the inline-block set below, collapsing the wrapper --}}
      class="mm-post-action {{ $formClass }}"
      style="{{ $mmFormStyle }}">
    @csrf
    <button type="submit" {{ $attributes->except('style') }} @if($mmButtonStyle) style="{{ $mmButtonStyle }}" @endif>{{ $slot }}</button>
</form>
