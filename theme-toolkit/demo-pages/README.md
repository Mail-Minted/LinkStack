# Demo bio pages

Generates the theme previews used on the Mail Minted marketing site, by
screenshotting **the real LinkStack page** for each profession theme.

```
php seed.php      # create/refresh a demo user + links per theme
node avatars.js   # monogram logo mark per demo, in that theme's palette
php artisan cache:clear   # findAvatar() caches the assets/img listing
node render.js    # screenshot each page -> mailminted frontend as WebP
```

`render.js` needs LinkStack running on `:8090` (the `linkstack-verify`
entry in the Mail Minted repo's `.claude/launch.json`).

## Why not theme-toolkit/previews.js

`previews.js` builds an **approximate mock** of a bio page from the
design system — a hand-written HTML string with placeholder buttons, a
grey circle where the avatar goes and grey dots for social icons. It has
never rendered a Blade template, so it cannot show real social icons,
real blocks, the real avatar or any theme CSS that lives outside the
generated tokens.

Its output (`themes/<slug>/preview.png`) also silently goes stale: those
files were rendered before `build.js` last regenerated every
`theme.json`, so they no longer matched the themes they claimed to show.

Anything a customer sees before they buy should come from this pipeline
instead.

## Content

`content.json` holds one entry per profession — business name, tagline,
socials, buttons and one rich block. Keep them plausible for the trade;
a prospect judges the whole product by these pages.

The rich block is the point. It is what the marketing copy means by
"book, pay, subscribe and press play", and it is the thing no
competitor's flat-rate mailbox product can show.

## Avatars

Demo users get a generated **monogram** rather than a photo or an icon:

- A user with no avatar falls through `findAvatar()` to
  `assets/linkstack/images/logo.svg` — the Mail Minted logo. On a
  marketing page that reads as though we brand every customer's page.
- Stock photos of people would need commercial licensing across 47
  professions, and invented "customers" undercut a page selling
  authenticity.
- `theme-toolkit/icons/` only covers 35 of the 47 themes. The 12
  photo-treatment themes have no icon and the set has no camera, bread
  or plane, so an icon-based set would look inconsistent.

The monogram takes each theme's own accent, button background and
heading font, so it looks designed for that theme.

## Social handles must match the studio's own list

The `socials` values in `content.json` are written straight into
`links.title`, and `linkstack/elements/icons.blade.php` renders them as
`fa-brands fa-<title>`. So a title that Font Awesome doesn't know, or
knows by a different name, silently draws the wrong glyph.

Take the values from `$brands` in
`resources/views/studio/partials/edit/social.blade.php` — the list the
customer actually picks from — rather than from the `buttons` table or
from memory.

The one that bites: **`x-twitter`, not `twitter`.** Font Awesome still
ships `.fa-twitter` as the legacy bird (`\f099`); the X mark is a
separate glyph, `.fa-x-twitter` (`\e61b`). The studio has only ever
offered `x-twitter` (added 2026-06-29, a month before launch), so no
real customer row says `twitter` — but hand-written demo content
bypasses the picker and can, which is exactly how an earlier pass here
produced bird icons and misdiagnosed them as a product defect.

## Gotchas

- `links.type` must be **NULL** for social icon rows, never `''` — the
  renderer treats any non-null type as a block name and `''` resolves
  `blocks::.display`, taking the page down.
- `type_params.custom_html` is what routes a row to the block renderer.
  Plain links must set it `false`; there is no
  `blocks/link/display.blade.php`.
- A block's `id:` in `blocks/<name>/config.yml` is the block id, **not** a
  `buttons` row. Typed blocks store `button_id = 1`.
- Blade failures render as HTTP 200 with an exception page, so `render.js`
  checks the page title and the resolved avatar rather than the status
  code.
