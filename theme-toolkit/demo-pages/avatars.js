#!/usr/bin/env node
/**
 * Generate a monogram logo mark for each demo bio page.
 *
 * WHY THIS EXISTS
 * A user with no uploaded avatar falls through findAvatar() to
 * assets/linkstack/images/logo.svg — the Mail Minted logo. On a
 * marketing page that is actively misleading: it reads as though every
 * customer's page is branded by us. So each demo gets its own mark.
 *
 * WHY A MONOGRAM AND NOT A PHOTO OR AN ICON
 * - Photos of people would need commercial licensing for 47 professions,
 *   and stand-in "customers" who don't exist are a bad look on a page
 *   selling authenticity.
 * - theme-toolkit/icons/ covers only 35 of the 47 themes; the 12
 *   photo-treatment themes (photographer, bakery, travel-creator, …)
 *   have no icon, and the set has no camera, bread or plane. A mixed
 *   icon/fallback set would look inconsistent across the marketing pages.
 * A monogram is what a lot of small businesses genuinely use, needs no
 * new assets, carries zero licensing risk, and inherits each theme's own
 * palette and heading font so it looks designed for that theme.
 *
 * Usage: node avatars.js         (reads users.json written by seed.php)
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { resolve, FONT_FACES } = require('../design-system');
const SPECS = require('../themes');

const ROOT = path.resolve(__dirname, '../..');
const FONTS_DIR = path.join(__dirname, '..', 'fonts');
const OUT_DIR = path.join(ROOT, 'assets/img');

// 2x the 160px the avatar element renders at, so it stays sharp.
const SIZE = 320;

/** "Aperture Studio" -> "AS"; "Marisol Vega" -> "MV"; "Wildbloom" -> "W". */
function monogram(name) {
  const words = name
    .replace(/[^A-Za-z0-9 &]/g, ' ')
    .split(/\s+/)
    .filter((w) => w && !/^(the|and|co|of|&)$/i.test(w));
  if (!words.length) return name.slice(0, 2).toUpperCase();
  if (words.length === 1) return words[0][0].toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

/** Inline the self-hosted woff2 so the headless render has the real face. */
function fontFace(family) {
  return FONT_FACES[family]
    .map(([file, weight]) => {
      const b64 = fs.readFileSync(path.join(FONTS_DIR, file + '.woff2')).toString('base64');
      return `@font-face{font-family:'${family}';font-weight:${weight};font-display:block;src:url(data:font/woff2;base64,${b64}) format('woff2');}`;
    })
    .join('\n');
}

function html(t, initials) {
  // Disc uses the theme's own button background so the mark sits in the
  // palette rather than on top of it; ring + letters take the accent,
  // which is what the theme already rings real avatars with.
  const disc = t.button.bg;
  const ring = t.avatarRing;
  const ink = t.vars['--accent-color'];

  return `<!doctype html><meta charset="utf-8"><style>
${fontFace(t.headingFont)}
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:${SIZE}px;height:${SIZE}px;background:transparent}
.mark{
  width:${SIZE}px;height:${SIZE}px;border-radius:50%;
  background:${disc};
  box-shadow:inset 0 0 0 ${Math.round(SIZE * 0.025)}px ${ring};
  display:flex;align-items:center;justify-content:center;
}
span{
  font-family:'${t.headingFont}',serif;
  font-weight:${t.headingWeight};
  font-size:${initials.length > 1 ? Math.round(SIZE * 0.36) : Math.round(SIZE * 0.46)}px;
  letter-spacing:${t.uppercase ? '0.06em' : '0.01em'};
  color:${ink};
  line-height:1;
  /* letter-spacing pushes the glyphs right; pull them back to true centre */
  text-indent:${t.uppercase ? '0.06em' : '0.01em'};
}
</style><div class="mark"><span>${initials}</span></div>`;
}

(async () => {
  const usersPath = path.join(__dirname, 'users.json');
  if (!fs.existsSync(usersPath)) {
    console.error('users.json missing — run `php seed.php` first.');
    process.exit(1);
  }
  const users = JSON.parse(fs.readFileSync(usersPath, 'utf8'));

  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({
    viewport: { width: SIZE, height: SIZE },
    deviceScaleFactor: 1,
  });

  for (const [slug, user] of Object.entries(users)) {
    const spec = SPECS.find((s) => s.slug === slug);
    if (!spec) {
      console.error(`  ✗ ${slug}: no theme spec`);
      continue;
    }
    const t = resolve(spec);
    const initials = monogram(user.name);

    await page.setContent(html(t, initials), { waitUntil: 'load' });
    await page.evaluate(async () => { await document.fonts.ready; });

    const out = path.join(OUT_DIR, `${user.id}.png`);
    await page.screenshot({ path: out, omitBackground: true });
    console.log(`  ✓ ${slug}  ${initials}  →  assets/img/${user.id}.png`);
  }

  await browser.close();

  // findAvatar() reads a cached directory listing (preloadDirectoryFiles,
  // key 'assets_img_files'), so a brand-new file is invisible until the
  // cache is dropped.
  console.log('\nNow clear the app cache so the new files are seen:');
  console.log('  php artisan cache:clear');
})();
