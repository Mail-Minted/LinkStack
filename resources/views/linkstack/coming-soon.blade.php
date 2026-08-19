{{--
  Holding page for a bio page that has never been built.

  Rendered by UserController@littlelink when a page has no blocks and
  no page_versions history. A Mail Minted domain provisions its
  LinkStack user during checkout, so the alternative is the customer's
  freshly bought domain going live as a blank stranger's profile.

  Deliberately self-contained — no theme, no layout include, no fonts
  or scripts fetched. This renders on a customer's own domain before
  they have chosen anything, so it should carry no styling opinion of
  ours beyond staying legible, and nothing here should be able to fail
  to load. noindex because a holding page must never be what search
  engines record for the customer's domain.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $pageHost ?: 'Coming soon' }}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --charcoal:       #1C1C22;
    --charcoal-mid:   #4A4A55;
    --charcoal-light: #9898A4;
    --surface:        #F8F8FA;
  }

  html, body { height: 100%; }

  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    background: var(--surface);
    color: var(--charcoal);
    -webkit-font-smoothing: antialiased;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
  }

  main { text-align: center; max-width: 32rem; }

  .host {
    font-size: 0.8125rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--charcoal-light);
    margin-bottom: 1.25rem;
    word-break: break-all;
  }

  h1 {
    font-size: clamp(1.75rem, 6vw, 2.5rem);
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1.15;
  }

  .lede {
    margin-top: 0.875rem;
    font-size: 1.0625rem;
    line-height: 1.6;
    color: var(--charcoal-mid);
  }
</style>
</head>
<body>
  <main>
    @if ($pageHost)
    <p class="host">{{ $pageHost }}</p>
    @endif
    <h1>Coming soon</h1>
    <p class="lede">This page is being set up. Check back shortly.</p>
  </main>
</body>
</html>
