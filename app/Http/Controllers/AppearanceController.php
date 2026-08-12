<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Live-preview Appearance editor — colors, background, typography,
 * button + avatar shape. Saves to users.theme_customization (JSON).
 * Public rendering picks this up in UserController::littlelink() and
 * injects override CSS into the linkstack-head-end stack.
 *
 * Sparse-override model (THEME-APPEARANCE-PLAN.md Phase 2): the blob
 * stores ONLY the knobs the user changed off their theme. The editor
 * hydrates its controls from the theme's manifest (themes/<slug>/
 * theme.json, shipped by theme-toolkit) merged with the sparse blob;
 * save() diffs the submitted state against the manifest and keeps
 * just the differences. Untouched aspects keep the theme's own CSS.
 *
 * Legacy blobs from the pre-sparse model (every key present) render
 * identically — they simply count as "everything overridden" — and
 * slim down automatically on their next save.
 */
class AppearanceController extends Controller
{
    /** Canonical list of Google Fonts available in the font picker. */
    public const GOOGLE_FONTS = [
        'Outfit', 'Inter', 'Poppins', 'Playfair Display', 'Merriweather',
        'Roboto Mono', 'DM Sans', 'Lora', 'Nunito', 'Raleway',
    ];

    /**
     * Real brand hexes for the "Brand colors" mode of the social
     * icons panel. Render emits per-brand CSS rules from this map.
     * Names match the Font Awesome class suffixes used in the icons
     * (e.g. fa-instagram → 'instagram').
     */
    public const BRAND_COLORS = [
        'instagram' => '#E4405F',
        'facebook'  => '#1877F2',
        'x-twitter' => '#000000',
        'github'    => '#181717',
        'linkedin'  => '#0A66C2',
        'tiktok'    => '#000000',
        'youtube'   => '#FF0000',
        'threads'   => '#000000',
        'twitch'    => '#9146FF',
        'pinterest' => '#E60023',
        'snapchat'  => '#FFFC00',
        'reddit'    => '#FF4500',
        'telegram'  => '#26A5E4',
        'behance'   => '#1769FF',
        'dribbble'  => '#EA4C89',
        'mastodon'  => '#6364FF',
        'bluesky'   => '#0085FF',
        'whatsapp'  => '#25D366',
        'discord'   => '#5865F2',
    ];

    /** Defaults — the shape every saved blob must conform to. */
    public static function defaults(): array
    {
        return [
            'colors' => [
                'primary'     => '#3b82f6',
                'background'  => '#ffffff',
                'text'        => '#111111',
                'button_text' => '#ffffff',
            ],
            'background' => [
                'type'               => 'solid',     // solid | gradient | image
                'solid'              => '#ffffff',
                'gradient_start'     => '#3b82f6',
                'gradient_end'       => '#8b5cf6',
                'gradient_direction' => 'to bottom', // to bottom | to right | to bottom right
                'image_url'          => '',
            ],
            'typography' => [
                'font' => '',  // empty = theme default
            ],
            'buttons' => [
                'shape' => 'rounded',  // pill | rounded | square
                'style' => 'filled',   // filled | outline | soft
            ],
            'avatar' => [
                'shape'    => 'circle', // circle | rounded_square
                // theme = the theme's disc behind the photo (generated
                // themes paint the button color there); none = fully
                // see-through, transparent avatars blend with the page.
                'backdrop' => 'theme',  // theme | none
            ],
            'social_icons' => [
                'color'            => 'auto',    // auto | brand | custom
                'color_custom'     => '#111111', // hex (only used when color === 'custom')
                'size'             => 'medium',  // small | medium | large | xl
                'spacing'          => 'normal',  // tight | normal | loose
                'background_style' => 'none',    // none | circle | rounded | solid
                'hover'            => 'lift',    // none | lift | glow | scale | colorshift
            ],
        ];
    }

    // show() removed 2026-07-05: the standalone /studio/appearance page
    // became the Appearance tab of the unified /studio/edit editor
    // (showEditor assembles $user/$saved/$fonts for the tab partial),
    // and the old GET route is now a redirect closure.

    public function save(Request $request)
    {
        $user = User::find(Auth::id());
        $data = $this->validated($request, $user);

        $sparse = self::diffAgainstManifest($data, self::themeManifest($user));
        $user->theme_customization = empty($sparse) ? null : json_encode($sparse, JSON_UNESCAPED_SLASHES);
        $user->save();

        // Auto-save (debounced fetch from the editor) wants a fast, tiny
        // reply — not a redirect it has to follow and download the whole
        // editor page for.
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect('/studio/edit#appearance')->with('success', 'Appearance saved.');
    }

    public function reset(Request $request)
    {
        $user = User::find(Auth::id());
        $user->theme_customization = null;
        $user->save();
        // The confirm copy promises "clears every color, font, shape,
        // and background you changed" — the uploaded background is a
        // FILE, and the bio renderer keys off file existence, so it
        // must go too or the image survives the reset.
        self::removeBackgroundFileIfPresent($user->id);
        // The theme gallery and the appearance controls share one pane
        // now — reset always lands on it.
        return redirect('/studio/edit#appearance')->with('success', 'Appearance reset to your theme.');
    }

    /**
     * Live-preview CSS for the editor iframe. Takes the form's current
     * (unsaved) state, diffs it against the theme manifest exactly like
     * save() would, and returns the override CSS that state would
     * produce — so the preview is byte-for-byte what a save would
     * publish. Values are sanitized inside AppearanceCss; no strict
     * validation here so a half-edited form still previews.
     */
    public function previewCss(Request $request)
    {
        $user = User::find(Auth::id());
        $manifest = self::themeManifest($user);

        $groups = ['colors', 'background', 'typography', 'buttons', 'avatar', 'social_icons'];
        $input = [];
        foreach ($groups as $g) {
            $v = $request->input($g);
            if (is_array($v)) {
                $input[$g] = $v;
            }
        }

        $sparse = self::diffAgainstManifest(self::mergeDeep($manifest, $input), $manifest);

        // Dot-keys of everything currently overridden — the editor
        // live-toggles its "edited" indicators from this list.
        $keys = [];
        foreach ($sparse as $group => $values) {
            if ($group === 'background' || !is_array($values)) {
                $keys[] = $group;
                continue;
            }
            foreach ($values as $k => $v) {
                $keys[] = $group . '.' . $k;
            }
        }

        return response()->json([
            'css'  => \App\Services\AppearanceCss::build($sparse, $manifest),
            'font' => \App\Services\AppearanceCss::fontHref($sparse),
            'keys' => $keys,
        ]);
    }

    /**
     * Upload a background image. The browser has already resized the
     * file to ≤1920px longest dimension at JPEG q=0.82 (see
     * appearance.js), so the max:2048 server cap is just a safety net.
     * Writes the file to /assets/img/background-img/ and stores the
     * leading-slash path on users.theme_customization JSON under
     * background.image_url. Also flips background.type to 'image' so
     * the upload immediately takes effect.
     */
    public function uploadBackgroundImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ], [
            'image.image' => __('messages.The selected file must be an image'),
            'image.mimes' => __('messages.The image must be') . ' JPEG, JPG, PNG, webP.',
            'image.max'   => __('messages.The image size should not exceed 2MB'),
        ]);

        $userId = Auth::id();
        $dir = base_path('assets/img/background-img');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->removeBackgroundFileIfPresent($userId);

        $file = $request->file('image');
        $fileName = $userId . '_' . time() . '.' . $file->extension();
        $file->move($dir, $fileName);

        // Sparse model: the upload is one background override on top of
        // the theme — never materialize other knobs into the blob.
        $user = User::find($userId);
        $sparse = self::sparseForUser($user);
        $sparse['background'] = [
            'type'      => 'image',
            'image_url' => '/assets/img/background-img/' . $fileName,
        ];
        $user->theme_customization = json_encode($sparse, JSON_UNESCAPED_SLASHES);
        $user->save();

        return response()->json(['ok' => true, 'image_url' => $sparse['background']['image_url']]);
    }

    /**
     * Remove the user's uploaded background. Deletes the file on disk
     * and drops the background override from the sparse blob — the
     * page falls back to the theme's own background.
     */
    public function removeBackgroundImage(Request $request)
    {
        $userId = Auth::id();
        $this->removeBackgroundFileIfPresent($userId);

        $user = User::find($userId);
        $sparse = self::sparseForUser($user);
        if (array_key_exists('background', $sparse)) {
            unset($sparse['background']);
            $user->theme_customization = empty($sparse) ? null : json_encode($sparse, JSON_UNESCAPED_SLASHES);
            $user->save();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Delete any existing background file(s) for this user. Same
     * scandir-directly pattern the avatar cleanup uses so a stale
     * reference never spins us into an infinite loop.
     *
     * Public static because the public bio renderer decides the
     * background by FILE EXISTENCE (linkstack/modules/theme.blade.php),
     * not by the sparse blob — so every code path that clears the
     * blob's background override (remove button, theme switch, reset
     * to theme) MUST also delete the file, or the old image keeps
     * rendering with no UI left to remove it.
     */
    public static function removeBackgroundFileIfPresent($userId): void
    {
        $dir = base_path('assets/img/background-img');
        if (!is_dir($dir)) return;
        // Two naming eras: {id}_{time}.{ext} (current uploads) and the
        // stock-LinkStack {id}.{ext} the old admin uploader wrote. Both
        // must go, or a legacy-named file survives a theme switch /
        // reset and keeps rendering (the bio page checks file
        // existence). The dot variant can't over-match: "12." never
        // prefixes user 123's files.
        $prefixes = [$userId . '_', $userId . '.'];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $matches = false;
            foreach ($prefixes as $p) {
                if (strpos($entry, $p) === 0) { $matches = true; break; }
            }
            if (!$matches) continue;
            $full = $dir . '/' . $entry;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }

    /**
     * The active theme's appearance manifest (themes/<slug>/theme.json,
     * emitted by theme-toolkit) merged over factory defaults, so every
     * key is always reachable. Default theme / missing manifest falls
     * back to pure factory defaults.
     */
    public static function themeManifest($user): array
    {
        $defaults = self::defaults();
        $theme = trim((string) ($user->theme ?? ''));
        if ($theme === '' || $theme === 'default') {
            return $defaults;
        }
        // basename() guards against traversal if the column is ever junk.
        $path = base_path('themes/' . basename($theme) . '/theme.json');
        if (!is_file($path)) {
            return $defaults;
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest)) {
            return $defaults;
        }
        unset($manifest['_meta']);
        return self::mergeDeep($defaults, $manifest);
    }

    /**
     * The theme's REAL resting button CSS (from theme.json _meta,
     * emitted by the generator) — used by the block editor's sample
     * preview so a theme-following block previews the theme's true
     * treatment (accent side-bars etc.), not the preset approximation.
     * Returns null when the user has page-wide button overrides (the
     * approximation is then the accurate render) or no manifest.
     */
    public static function themeButtonCss($user): ?string
    {
        $sparse = self::sparseForUser($user);
        if (data_get($sparse, 'buttons') !== null
            || data_get($sparse, 'colors.primary') !== null
            || data_get($sparse, 'colors.button_text') !== null) {
            return null;
        }
        $theme = trim((string) ($user->theme ?? ''));
        if ($theme === '' || $theme === 'default') {
            return null;
        }
        $path = base_path('themes/' . basename($theme) . '/theme.json');
        if (!is_file($path)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        $css = data_get($manifest, '_meta.button_css');
        return (is_string($css) && $css !== '') ? $css : null;
    }

    /**
     * The raw sparse blob — only the knobs the user changed off their
     * theme. Empty array when nothing is customized.
     */
    public static function sparseForUser($user): array
    {
        if (!$user || empty($user->theme_customization)) {
            return [];
        }
        $parsed = json_decode($user->theme_customization, true);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * What the page actually renders: theme manifest with the user's
     * sparse overrides on top. The editor hydrates its controls from
     * this so the knobs always show the truth.
     */
    public static function effectiveForUser($user): array
    {
        return self::mergeDeep(self::themeManifest($user), self::sparseForUser($user));
    }

    /**
     * Reduce a full submitted state to the sparse overrides: keep only
     * leaves that differ from the theme manifest. `background` diffs as
     * one group (its fields only mean anything together with the type),
     * and `colors.background` is never stored — it isn't user-editable;
     * it exists as the image-fallback color and comes from the theme.
     */
    public static function diffAgainstManifest(array $data, array $manifest): array
    {
        $sparse = [];

        $bg = self::normalizeBackground($data['background'] ?? []);
        if ($bg !== self::normalizeBackground($manifest['background'] ?? [])) {
            $sparse['background'] = $bg;
        }

        foreach (['colors', 'typography', 'buttons', 'avatar', 'social_icons'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $key => $value) {
                if ($group === 'colors' && $key === 'background') {
                    continue;
                }
                if (is_array($value)) {
                    continue; // no nested groups exist below this level
                }
                if ((string) $value !== (string) data_get($manifest, "{$group}.{$key}")) {
                    $sparse[$group][$key] = $value;
                }
            }
        }

        return $sparse;
    }

    /**
     * Strip a background group down to the fields its type actually
     * uses, so stale values from inactive sub-panels (the form posts
     * every field regardless of selected type) don't read as changes.
     */
    private static function normalizeBackground(array $bg): array
    {
        $type = $bg['type'] ?? 'solid';
        return match ($type) {
            'gradient' => [
                'type'               => 'gradient',
                'gradient_start'     => (string) ($bg['gradient_start'] ?? ''),
                'gradient_end'       => (string) ($bg['gradient_end'] ?? ''),
                'gradient_direction' => (string) ($bg['gradient_direction'] ?? ''),
            ],
            'image' => [
                'type'      => 'image',
                'image_url' => (string) ($bg['image_url'] ?? ''),
            ],
            default => [
                'type'  => 'solid',
                'solid' => (string) ($bg['solid'] ?? ''),
            ],
        };
    }

    public static function mergeDeep(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::mergeDeep($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    private function validated(Request $request, User $user): array
    {
        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];

        $rules = [
            'colors.primary'     => $hex,
            // colors.background is no longer in the UI (the Background
            // section's solid / gradient covers it); it stays in storage
            // from defaults() so the image-type fallback color survives.
            'colors.text'        => $hex,
            'colors.button_text' => $hex,

            'background.type'               => ['required', 'in:solid,gradient,image'],
            'background.solid'              => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background.gradient_start'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background.gradient_end'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background.gradient_direction' => ['nullable', 'in:to bottom,to right,to bottom right'],
            // Accept http(s) URLs (legacy / external), uploaded
            // backgrounds under /assets/, or theme-shipped photos under
            // /themes/ (photo-theme manifests round-trip their own
            // background through the form). Empty clears the field.
            'background.image_url'          => ['nullable', 'string', 'max:2048', 'regex:#^(|https?://.+|/assets/img/background-img/.+|/themes/[A-Za-z0-9._-]+/extra/custom-assets/.+)$#i'],

            'typography.font' => ['nullable', 'string', 'in:' . implode(',', array_merge([''], self::GOOGLE_FONTS))],

            'buttons.shape' => ['required', 'in:pill,rounded,square'],
            'buttons.style' => ['required', 'in:filled,outline,soft'],

            'avatar.shape'    => ['required', 'in:circle,rounded_square,off'],
            'avatar.backdrop' => ['required', 'in:theme,none'],

            'social_icons.color'            => ['required', 'in:auto,brand,custom'],
            'social_icons.color_custom'     => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'social_icons.size'             => ['required', 'in:small,medium,large,xl'],
            'social_icons.spacing'          => ['required', 'in:tight,normal,loose'],
            'social_icons.background_style' => ['required', 'in:none,circle,rounded,solid'],
            'social_icons.hover'            => ['required', 'in:none,lift,glow,scale,colorshift'],
        ];

        $validated = $request->validate($rules);

        // Fill gaps from the THEME's values (not factory defaults):
        // anything the form didn't submit reads as "unchanged from the
        // theme," so the sparse diff drops it.
        return self::mergeDeep(self::themeManifest($user), $validated);
    }
}
