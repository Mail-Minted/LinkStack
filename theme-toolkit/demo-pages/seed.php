<?php
/**
 * Seed one demo bio page per profession theme.
 *
 * Part of the demo-pages pipeline, which exists because the Mail Minted
 * marketing site needs previews of what a customer ACTUALLY gets:
 *
 *     seed.php  →  avatars.js  →  render.js
 *
 * theme-toolkit/previews.js is NOT that. It assembles an approximate
 * mock page from the design system and has never rendered a Blade
 * template, so it misses real social icons, real blocks and the real
 * avatar. Its output (themes/<slug>/preview.png) also went stale the
 * moment build.js regenerated the themes. Prefer this pipeline for
 * anything customer-facing.
 *
 * Usage:  php seed.php [slug ...]      (no args = every slug in content.json)
 *
 * Demo users are created under the reserved @mm-demo.invalid domain so
 * they are trivially identifiable and can never collide with real rows.
 * Re-running is safe: it updates in place and rebuilds the link list.
 */

$ROOT = dirname(__DIR__, 2);
require $ROOT . '/vendor/autoload.php';
$app = require_once $ROOT . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const DEMO_DOMAIN = 'mm-demo.invalid';

/*
 * button_id values, as the app itself stores them.
 *
 * NOTE: a block's `id:` in blocks/<name>/config.yml is the BLOCK id, not
 * a row in `buttons`. Typed blocks all store button_id = 1 and carry the
 * block name in links.type; using the config.yml id trips the
 * links.button_id foreign key.
 */
const B_CUSTOM = 1;   // every typed block (contact_form, youtube_video, …)
const B_LINK   = 2;   // custom_website — a plain labelled button
const B_ICON   = 94;  // social icon row

/**
 * type_params, in the shape the block editor writes.
 *
 * `custom_html` is the switch that matters: elements/buttons.blade.php
 * routes a row to blocks::<type>.display only when it is TRUE. A plain
 * link must be false, or the renderer looks for a non-existent
 * blocks/link/display.blade.php and the whole page dies with
 * "View [link.display] not found."
 */
function params(bool $customHtml, array $extra = []): string
{
    return json_encode($extra + [
        'custom_html'       => $customHtml,
        'ignore_container'  => false,
        'include_libraries' => [],
    ]);
}

$content = json_decode(file_get_contents(__DIR__ . '/content.json'), true);
unset($content['_readme']);

$want  = array_slice($argv, 1);
$specs = $want ? array_intersect_key($content, array_flip($want)) : $content;

if (!$specs) {
    fwrite(STDERR, "No matching slugs in content.json\n");
    exit(1);
}

$map = [];

foreach ($specs as $slug => $spec) {
    $handle = $slug . '-demo';
    $email  = $slug . '@' . DEMO_DOMAIN;
    $now    = date('Y-m-d H:i:s');

    $userId = DB::table('users')->where('email', $email)->value('id');

    $row = [
        'name'                   => $spec['name'],
        'littlelink_name'        => $handle,
        'littlelink_description' => $spec['tagline'],
        'theme'                  => $slug,
        'role'                   => 'user',
        'block'                  => 'no',
        'email_verified_at'      => $now,
        'updated_at'             => $now,
    ];

    if ($userId) {
        DB::table('users')->where('id', $userId)->update($row);
        DB::table('links')->where('user_id', $userId)->delete();
    } else {
        $userId = DB::table('users')->insertGetId($row + [
            'email'      => $email,
            'password'   => bcrypt(bin2hex(random_bytes(16))),
            'created_at' => $now,
        ]);
    }

    $order  = 0;
    $insert = function (array $link) use (&$order, $userId, $now) {
        DB::table('links')->insert($link + [
            'user_id'      => $userId,
            'order'        => $order++,
            'click_number' => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    };

    // Social icon rows. type MUST be NULL, not '': the renderer treats
    // any non-null type as a block name, so '' resolves blocks::.display
    // and takes the page down with "View [.display] not found."
    foreach ($spec['socials'] as $platform) {
        $insert([
            'button_id'   => B_ICON,
            'title'       => $platform,
            'link'        => 'https://' . $platform . '.com/' . $handle,
            'type'        => null,
            'type_params' => null,
        ]);
    }

    foreach ($spec['links'] as $link) {
        $insert([
            'button_id'   => B_LINK,
            'title'       => $link['title'],
            'link'        => $link['url'],
            'type'        => 'link',
            'type_params' => params(false),
        ]);
    }

    $rich = $spec['rich'];
    $insert([
        'button_id'   => B_CUSTOM,
        'title'       => $rich['title'],
        'link'        => '',
        'type'        => $rich['type'],
        'type_params' => params(true, $rich['params'] ?? []),
    ]);

    $map[$slug] = ['id' => $userId, 'handle' => $handle, 'name' => $spec['name']];
    echo "  ✓ {$slug}  →  /@{$handle}  (user {$userId})\n";
}

// avatars.js needs the ids: findAvatar() resolves assets/img/<userId>.*
file_put_contents(
    __DIR__ . '/users.json',
    json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "\nWrote users.json (" . count($map) . " demo pages)\n";
