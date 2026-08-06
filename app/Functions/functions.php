<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

if (!function_exists('preloadDirectoryFiles')) {
    /**
     * Preload all files in a directory and optionally cache in Redis or Laravel cache.
     *
     * @param string $directory
     * @param string $cacheKey Unique cache key
     * @param int $ttl Cache time in seconds
     * @return array Array of filenames
     */
    function preloadDirectoryFiles(string $directory, string $cacheKey, int $ttl = 3600): array
    {
        static $memoryCache = [];

        $listing = (is_dir($directory) ? scandir($directory) : false) ?: [];
        $fingerprint = md5(json_encode($listing));

        // Return from memory only while the directory is unchanged. The
        // fingerprint is what makes every tier honest: without it here,
        // one read of a directory froze that listing for the rest of the
        // process, so an upload or delete later in the same request was
        // invisible to findAvatar()/findBackground().
        if (isset($memoryCache[$directory]) && ($memoryCache[$directory]['fingerprint'] ?? null) === $fingerprint) {
            return $memoryCache[$directory]['files'];
        }

        // Try Redis first
        if (class_exists('Redis')) {
            try {
                $cached = Redis::get($cacheKey);
                if ($cached) {
                    $cached = json_decode($cached, true);
                    if (isset($cached['fingerprint']) && $cached['fingerprint'] === $fingerprint) {
                        $memoryCache[$directory] = $cached;
                        return $cached['files'];
                    }
                }
            } catch (\Exception $e) {
                // Redis not available, fallback to local memory
            }
        }

        // The listing read for the fingerprint IS the answer — no second
        // scandir, and no window where the two could disagree.
        $files = $listing;

        // Cache in Redis if possible
        $data = [
            'fingerprint' => $fingerprint,
            'files' => $files
        ];
        if (class_exists('Redis')) {
            try {
                Redis::setex($cacheKey, $ttl, json_encode($data));
            } catch (\Exception $e) {
                // fallback silently
            }
        } else {
            // Laravel cache fallback
            Cache::put($cacheKey, $data, $ttl);
        }

        $memoryCache[$directory] = $data;

        return $files;
    }
}

function findFile($name)
{
    $directory = base_path("/assets/linkstack/images/");
    $files = preloadDirectoryFiles($directory, 'linkstack_images_files');

    $pattern = '/^' . preg_quote($name, '/') . '(_\w+)?\.\w+$/i';
    foreach ($files as $file) {
        if (preg_match($pattern, $file)) {
            return $file;
        }
    }
    return "error.error";
}

function findAvatar($name)
{
    $directory = base_path("assets/img");
    $files = preloadDirectoryFiles($directory, 'assets_img_files');

    $pattern = '/^' . preg_quote($name, '/') . '(_\w+)?\.\w+$/i';
    foreach ($files as $file) {
        if (preg_match($pattern, $file)) {
            return "assets/img/" . $file;
        }
    }
    return "error.error";
}

function findFavicon($name)
{
    // Fresh glob, not the 1-hour directory cache: an upload or removal
    // must show on the very next request (see the stale-cache note on
    // UserController::removeAvatarFileIfPresent), and the pattern only
    // ever targets one user's file so a full listing buys nothing.
    $matches = glob(base_path('assets/img/favicon-img/') . $name . '_*.*');
    foreach ($matches ?: [] as $file) {
        if (is_file($file)) {
            return basename($file);
        }
    }
    return "error.error";
}

function findBackground($name)
{
    $directory = base_path("assets/img/background-img/");
    $files = preloadDirectoryFiles($directory, 'assets_img_background_files');

    $pattern = '/^' . preg_quote($name, '/') . '(_\w+)?\.\w+$/i';
    foreach ($files as $file) {
        if (preg_match($pattern, $file)) {
            return $file;
        }
    }
    return "error.error";
}

function analyzeImageBrightness($file) {
    try {
    $file = base_path('assets/img/background-img/'.$file);
  
    // Get image information using getimagesize
    $imageInfo = getimagesize($file);
    if (!$imageInfo) {
      return 'dark';
    }
  
    // Get the image type
    $type = $imageInfo[2];
  
    // Load the image based on its type
    switch ($type) {
      case IMAGETYPE_JPEG:
      case IMAGETYPE_JPEG2000:
        $img = imagecreatefromjpeg($file);
        break;
      case IMAGETYPE_PNG:
        $img = imagecreatefrompng($file);
        break;
      default:
        return 'dark';
    }
  
    // Get image dimensions
    $width = imagesx($img);
    $height = imagesy($img);
  
    // Calculate the average brightness of the image
    $total_brightness = 0;
    for ($x=0; $x<$width; $x++) {
      for ($y=0; $y<$height; $y++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $brightness = (int)(($r + $g + $b) / 3);
        $total_brightness += $brightness;
      }
    }
    $avg_brightness = $total_brightness / ($width * $height);
  
    // Determine if the image is more dark or light
    if ($avg_brightness < 128) {
      return 'dark';
    } else {
      return 'light';
    }
      } catch (\Throwable $th) {
          return null;
      }
  }
  
  function infoIcon($tip) {
    echo '
      <div class="d-flex justify-content-center align-items-center">
        <a data-bs-toggle="tooltip" data-bs-placement="bottom" title="' . $tip . '">
          <svg class="icon-32" width="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M7.67 1.99927H16.34C19.73 1.99927 22 4.37927 22 7.91927V16.0903C22 19.6203 19.73 21.9993 16.34 21.9993H7.67C4.28 21.9993 2 19.6203 2 16.0903V7.91927C2 4.37927 4.28 1.99927 7.67 1.99927ZM11.99 9.06027C11.52 9.06027 11.13 8.66927 11.13 8.19027C11.13 7.70027 11.52 7.31027 12.01 7.31027C12.49 7.31027 12.88 7.70027 12.88 8.19027C12.88 8.66927 12.49 9.06027 11.99 9.06027ZM12.87 15.7803C12.87 16.2603 12.48 16.6503 11.99 16.6503C11.51 16.6503 11.12 16.2603 11.12 15.7803V11.3603C11.12 10.8793 11.51 10.4803 11.99 10.4803C12.48 10.4803 12.87 10.8793 12.87 11.3603V15.7803Z" fill="currentColor"></path>
          </svg>
        </a>
      </div>
    ';
  }

function external_file_get_contents($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:80.0) Gecko/20100101 Firefox/80.0');
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function uri($path) {
    $url = str_replace(['http://', 'https://'], '', url(''));
    return "//" . $url . "/" . $path;
}

function footer($key)
{
    $upperStr = strtoupper($key);
    if (env('TITLE_FOOTER_'.$upperStr) == "") {
        $title = __('messages.footer.'.$key);
    } else {
        $title = env('TITLE_FOOTER_'.$upperStr);
    }
    return $title;
}

function strip_tags_except_allowed_protocols($str) {
    preg_match_all('/<a[^>]+>(.*?)<\/a>/i', $str, $matches, PREG_SET_ORDER);

    foreach ($matches as $val) {
        if (!preg_match('/href=["\'](http:|https:|mailto:|tel:)[^"\']*["\']/', $val[0])) {
            $str = str_replace($val[0], $val[1], $str);
        }
    }

    return $str;
}

if (!function_exists('purify_user_html')) {
    /**
     * Sanitize user-supplied HTML for safe rendering on public pages.
     *
     * Replaces the old strip_tags() + strip_tags_except_allowed_protocols()
     * combination, which was bypassable: PHP's strip_tags never removes
     * ATTRIBUTES from allowed tags, and the protocol helper only inspected
     * <a> elements. So `<a href="https://x" onclick="…">` and
     * `<p onmouseover="…">` both survived and rendered as live script
     * when ALLOW_USER_HTML=true — a stored-XSS hole (customer content
     * runs on the same origin as the dashboard/admin).
     *
     * HTMLPurifier drops every tag, attribute, and URI scheme not on its
     * allowlist below (all on* event handlers, style, javascript:/data:
     * URIs, etc.), so the output is safe to echo with {!! !!}.
     *
     * Allowlist mirrors the tags the studio's rich-text controls emit
     * (bold / italic / links / lists / headings / quotes).
     */
    function purify_user_html($html) {
        static $purifier = null;

        if ($purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed',
                'a[href|title],p,br,strong,b,em,i,u,s,ul,ol,li,blockquote,h2,h3,h4');
            $config->set('URI.AllowedSchemes', [
                'http' => true, 'https' => true, 'mailto' => true, 'tel' => true,
            ]);
            // External links get target=_blank + rel=noopener automatically.
            $config->set('HTML.TargetBlank', true);

            // HTMLPurifier writes a small serializer cache; point it at a
            // writable storage dir (created if missing) so it never tries
            // to write inside vendor/.
            $cacheDir = storage_path('app/htmlpurifier');
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $config->set('Cache.SerializerPath', $cacheDir);

            $purifier = new \HTMLPurifier($config);
        }

        return $purifier->purify((string) $html);
    }
}

if (!function_exists('purge_user_uploads')) {
    /**
     * Delete every uploaded file belonging to a user — their avatar
     * (assets/img) and background image (assets/img/background-img).
     *
     * Single source of truth for "remove this user's files". Called on
     * account deletion (admin panel, self-serve, and the Mail Minted
     * deprovision API) so a cancelled/removed customer doesn't leave
     * orphaned images on disk, and by the storage:reconcile command.
     *
     * File naming matches findAvatar()/findBackground(): "<id>.<ext>"
     * or "<id>_<suffix>.<ext>". The pattern anchors <id> followed by
     * "_" or "." so id "1" never matches "10_x.jpg" (collision-safe).
     *
     * @return int number of files deleted
     */
    function purge_user_uploads($userId): int
    {
        $userId = (string) $userId;
        if ($userId === '' || !ctype_digit($userId)) {
            return 0;
        }

        $dirs = [
            base_path('assets/img'),
            base_path('assets/img/background-img'),
            base_path('assets/img/favicon-img'),
        ];
        $pattern = '/^' . preg_quote($userId, '/') . '(_\w+)?\.\w+$/i';
        $deleted = 0;

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!preg_match($pattern, $entry)) {
                    continue;
                }
                $full = $dir . '/' . $entry;
                if (is_file($full) && @unlink($full)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

if(!function_exists('setBlockAssetContext')) {
  function setBlockAssetContext($type = null) {
      static $currentType = null;
      if ($type !== null) {
          $currentType = $type;
      }
      return $currentType;
  }
}

// Get custom block assets
if(!function_exists('block_asset')) {
  function block_asset($file) {
      $type = setBlockAssetContext(); // Retrieve the current type context
      return url("block-asset/$type?asset=$file");
  }
}

if(!function_exists('get_block_file_contents')) {
  function get_block_file_contents($file) {
      $type = setBlockAssetContext(); // Retrieve the current type context
      return file_get_contents(base_path("blocks/$type/$file"));
  }
}

function block_text_translation_check($text) {
  if (empty($text)) {
    return false;
  }
  $translate = __("messages.$text");
  return $translate === "messages.$text" ? true : false;
}

function block_text($text) {
  $translate = __("messages.$text");
  return $translate === "messages.$text" ? $text : $translate;
}

function bt($text) {
  return block_text($text);
}
/**
 * Per-block appearance overrides for rich blocks (Phase 6,
 * THEME-APPEARANCE-PLAN.md). Returns a scoped <style> block ('' when
 * the block has no styling of its own) applying the block's saved
 * choices to selectors INSIDE its wrapper:
 *
 *   'id'      — the wrapper element's id (required)
 *   'button'  — action-button selector(s); receives the block's
 *               custom_css string (generated by the block editor's
 *               visual controls, every declaration !important)
 *   'heading' — colored by type_params.appearance_heading
 *   'text'    — colored by type_params.appearance_color ('' selector
 *               targets the wrapper itself)
 *
 * The #id scoping plus the generator's !important outrank both theme
 * CSS and the page-wide appearance override, so a per-block choice
 * always wins — but only for this one block. Blocks with no overrides
 * emit nothing and keep following the theme.
 */
if (!function_exists('block_appearance_style')) {
  function block_appearance_style($link, array $selectors): string
  {
      $wrapId = (string) ($selectors['id'] ?? '');
      if ($wrapId === '') {
          return '';
      }
      $tp = json_decode($link->type_params ?? '{}', true);
      $tp = is_array($tp) ? $tp : [];
      $rules = [];

      $btnCss = trim((string) ($link->custom_css ?? ''));
      if ($btnCss !== '' && !empty($selectors['button'])) {
          // Neutralize anything that could escape the <style> element.
          $btnCss = str_replace(['<', '>'], '', $btnCss);
          $scoped = array_map(
              fn ($s) => "#{$wrapId} {$s}",
              (array) $selectors['button']
          );
          // When the block is collapsed, its accordion summary is the
          // block's button face — the custom styling applies there
          // too. The summary sits OUTSIDE the block wrapper (the
          // <details> wraps both), so it's scoped by the accordion id.
          if (!empty($selectors['summary_id'])) {
              $scoped[] = "#{$selectors['summary_id']} > .block-accordion-summary";
          }
          $rules[] = implode(', ', $scoped) . " { {$btnCss} }";
      }

      foreach (['heading' => 'appearance_heading', 'text' => 'appearance_color'] as $slot => $key) {
          $hex = trim((string) ($tp[$key] ?? ''));
          if ($hex !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $hex) && !empty($selectors[$slot] ?? null)) {
              $scoped = implode(', ', array_map(
                  fn ($s) => $s === '' ? "#{$wrapId}" : "#{$wrapId} {$s}",
                  (array) $selectors[$slot]
              ));
              $rules[] = "{$scoped} { color: {$hex} !important; }";
          }
      }

      return $rules ? "<style>\n" . implode("\n", $rules) . "\n</style>" : '';
  }
}

/**
 * Neutralize dangerous URL schemes before a user-supplied URL is placed
 * in an href. Blade's {{ }} escaping stops attribute breakout but does
 * NOT stop the `javascript:` / `data:` / `vbscript:` schemes, which
 * execute script when the link is clicked (stored XSS on a public bio
 * page). Allowlists http/https/mailto/tel and relative/protocol-
 * relative URLs; anything else becomes '#'. Runs at render time so it
 * also covers URLs already stored in the DB.
 */
if (!function_exists('mm_safe_href')) {
  function mm_safe_href($url): string
  {
      $url = (string) $url;
      if (trim($url) === '') {
          return $url;
      }
      // Detect the scheme on a copy with all control chars + spaces
      // stripped, so "java\tscript:" or leading whitespace can't smuggle
      // a blocked scheme past the check (browsers strip those before
      // parsing the scheme).
      $probe = preg_replace('/[\x00-\x20]+/', '', $url);
      if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m)) {
          $scheme = strtolower($m[1]);
          if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
              return '#';
          }
      }
      return $url;
  }
}

/**
 * Sanitize per-block custom CSS at rest (defense in depth).
 *
 * The custom_css string is generated by the block editor's visual
 * controls (background / color / border / radius / shadow / backdrop-
 * filter, all !important) plus an optional freeform "Advanced" tweak.
 * It renders attribute-escaped inline and with <>-stripping inside the
 * scoped <style> (block_appearance_style), so JS/tag breakout is
 * already blocked. This neutralizes the remaining constructs that turn
 * "styling" into fetching or scripting, so nothing dangerous is ever
 * stored: CSS at-rules (@import/@charset/…), legacy IE/Moz script-in-
 * CSS (expression(), behavior:, -moz-binding), and script URI schemes
 * in url(). A blocklist of dangerous constructs is used deliberately
 * rather than a strict property allowlist, because the Advanced box is
 * an intentional power-user feature and the presets rely on !important
 * and properties (backdrop-filter, box-shadow) a tight allowlist would
 * strip. url() itself stays allowed (custom button backgrounds); only
 * dangerous schemes inside it are removed.
 */
if (!function_exists('mm_sanitize_block_css')) {
  function mm_sanitize_block_css($css): string
  {
      $css = (string) $css;
      if (trim($css) === '') {
          return '';
      }
      // Length cap — generated declarations + a reasonable tweak are
      // well under this; larger is abuse.
      if (strlen($css) > 4000) {
          $css = substr($css, 0, 4000);
      }
      // Drop CSS comments (can obfuscate/hide the tokens below).
      $css = preg_replace('#/\*.*?\*/#s', '', $css);
      // Remove chars that could break out of <style> / style="" and the
      // backslash escapes used to obfuscate the blocked tokens.
      $css = str_replace(['<', '>', '\\'], '', $css);
      // Neutralize fetch/script constructs (case-insensitive).
      $css = preg_replace('/@\s*[a-z-]+/i', '', $css);                 // at-rules (@import …)
      $css = preg_replace('/expression\s*\(/i', '', $css);            // IE expression()
      $css = preg_replace('/(behavior|-moz-binding|binding)\s*:/i', '', $css);
      $css = preg_replace('/(javascript|vbscript)\s*:/i', '', $css);  // scheme in url()
      return trim($css);
  }
}

/**
 * The per-request CSP nonce (set by App\Http\Middleware\Headers before
 * the view renders). Every inline <script> on a CSP-enforced page must
 * carry nonce="{{ csp_nonce() }}" or it will be blocked. Returns '' on
 * requests where the middleware didn't run (console, sub-requests) —
 * those pages carry no CSP either, so an empty nonce is harmless.
 */
if (!function_exists('csp_nonce')) {
  function csp_nonce(): string
  {
      return (string) request()->attributes->get('csp_nonce', '');
  }
}

/**
 * Signed render-time token for the contact form's timing check. A real
 * visitor takes a few seconds to fill the form; bots post instantly.
 * Format: "<unix_ts>.<hmac>" — the HMAC (keyed by the app key) stops a bot
 * forging an older timestamp to look like it waited.
 */
if (!function_exists('cf_form_token')) {
  function cf_form_token(): string
  {
      $ts  = (string) time();
      $sig = hash_hmac('sha256', $ts, (string) config('app.key'));
      return $ts . '.' . $sig;
  }
}

/**
 * Validate a contact-form timing token and return the seconds elapsed since
 * it was issued, or null if it is missing or forged. The caller rejects
 * implausibly fast submissions (see ContactFormController::submit).
 */
if (!function_exists('cf_token_elapsed')) {
  function cf_token_elapsed(?string $token): ?int
  {
      if (!is_string($token) || !str_contains($token, '.')) {
          return null;
      }
      [$ts, $sig] = explode('.', $token, 2);
      if ($ts === '' || !ctype_digit($ts)) {
          return null;
      }
      $expected = hash_hmac('sha256', $ts, (string) config('app.key'));
      if (!hash_equals($expected, (string) $sig)) {
          return null;
      }
      return time() - (int) $ts;
  }
}
