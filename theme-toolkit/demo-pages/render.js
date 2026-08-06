#!/usr/bin/env node
/**
 * Screenshot each seeded demo bio page from the RUNNING LinkStack app
 * and write a web-sized WebP into the Mail Minted frontend.
 *
 * Final stage of:  seed.php  →  avatars.js  →  render.js
 *
 * This deliberately screenshots the real /@handle page. Do not swap it
 * for theme-toolkit/previews.js, which renders an approximate mock and
 * has never touched a Blade template — see the note at the top of
 * seed.php.
 *
 * Requires the `linkstack-verify` preview server on :8090.
 * Usage: node render.js [slug ...]     (no args = everything in users.json)
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const BASE = 'http://localhost:8090';
const OUT_DIR = '/Users/jameskoch/dev/mailminted/frontend/public/landing-assets/bio-themes';

// A phone-shaped crop, rendered at 2x so it stays sharp displayed at
// roughly 360px wide on the marketing pages.
const VIEWPORT = { width: 390, height: 844 };

(async () => {
  const usersPath = path.join(__dirname, 'users.json');
  if (!fs.existsSync(usersPath)) {
    console.error('users.json missing — run `php seed.php` first.');
    process.exit(1);
  }
  const users = JSON.parse(fs.readFileSync(usersPath, 'utf8'));

  const want = process.argv.slice(2);
  const slugs = want.length ? want : Object.keys(users);

  fs.mkdirSync(OUT_DIR, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: VIEWPORT, deviceScaleFactor: 2 });

  let failed = 0;
  for (const slug of slugs) {
    const user = users[slug];
    if (!user) {
      console.error(`  ✗ ${slug}: not in users.json`);
      failed++;
      continue;
    }

    const res = await page.goto(`${BASE}/@${user.handle}`, { waitUntil: 'networkidle' });
    if (!res || res.status() !== 200) {
      console.error(`  ✗ ${slug}: HTTP ${res && res.status()}`);
      failed++;
      continue;
    }

    // A Blade failure renders as HTTP 200 with the exception page, so
    // trust the title rather than the status code.
    const title = await page.title();
    if (/not found|error|exception/i.test(title)) {
      console.error(`  ✗ ${slug}: rendered an error page — "${title}"`);
      failed++;
      continue;
    }

    // Confirm the avatar is the demo's own mark and not the Mail Minted
    // logo fallback: a wrong avatar is silent and ships straight to the
    // marketing site.
    const avatarSrc = await page.getAttribute('#avatar', 'src');
    if (!avatarSrc || /logo\.svg/.test(avatarSrc)) {
      console.error(`  ✗ ${slug}: avatar fell back to the Mail Minted logo`
        + ` — run avatars.js then \`php artisan cache:clear\``);
      failed++;
      continue;
    }

    await page.evaluate(async () => { await document.fonts.ready; });
    // Theme backgrounds are large photos; let decode settle so the shot
    // never catches a half-painted hero.
    await page.waitForTimeout(400);

    // Playwright only encodes png/jpeg, so shoot PNG and hand it to
    // cwebp (brew install webp) for a much smaller file at the same
    // visual quality — these are photographic theme backgrounds.
    const tmp = path.join(OUT_DIR, `.${slug}.png`);
    const out = path.join(OUT_DIR, `${slug}.webp`);
    await page.screenshot({ path: tmp });
    execFileSync('cwebp', ['-quiet', '-q', '82', tmp, '-o', out]);

    // A second, small copy for the grids. The full render is ~45 KB and
    // 780px wide; the theme index shows all 47 at ~180px, so serving the
    // full set there would be 2.2 MB to draw thumbnails. This is ~13 KB.
    const thumb = path.join(OUT_DIR, `${slug}-thumb.webp`);
    execFileSync('cwebp', ['-quiet', '-q', '80', '-resize', '260', '0', tmp, '-o', thumb]);
    fs.unlinkSync(tmp);

    const kb = Math.round(fs.statSync(out).size / 1024);
    const tkb = Math.round(fs.statSync(thumb).size / 1024);
    console.log(`  ✓ ${slug}  ${kb} KB + ${tkb} KB thumb  ${title}`);
  }

  await browser.close();
  if (failed) console.error(`\n${failed} page(s) failed.`);
  process.exit(failed ? 1 : 0);
})();
