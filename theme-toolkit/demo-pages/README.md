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
