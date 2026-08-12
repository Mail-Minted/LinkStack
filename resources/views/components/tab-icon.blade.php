@props(['userId' => null])

{{--
    The browser-tab icon (favicon), white-labelled per customer.

    Resolution order: the given user's uploaded icon, then the site-wide
    favicon an admin set in /admin/site, then the bundled LinkStack logo.

    Pass :user-id explicitly on a public bio page -- the visitor is not
    the page owner, and may not be logged in at all. Omit it on the
    studio/admin chrome, where the icon should follow whoever is signed
    in, so a customer editing their page sees their own branding in the
    tab. While an admin is impersonating, auth()->id() is the customer,
    which is the intent.

    NB not to be confused with components/favicon.blade.php, which
    defines the getFavIcon() helper for per-LINK icons and emits nothing.
--}}
@php
    $mmIconUser = $userId ?? (auth()->check() ? auth()->id() : null);
    $mmIcon     = $mmIconUser !== null ? findFavicon($mmIconUser) : 'error.error';
    $mmSiteIcon = findFile('favicon');
@endphp
@if($mmIcon !== 'error.error')
<link rel="icon" href="{{ asset('assets/img/favicon-img/'.$mmIcon) }}">
@elseif(file_exists(base_path('assets/linkstack/images/').$mmSiteIcon))
<link rel="icon" type="image/png" href="{{ asset('assets/linkstack/images/'.$mmSiteIcon) }}">
@else
<link rel="icon" type="image/svg+xml" href="{{ asset('assets/linkstack/images/logo.svg') }}">
@endif
