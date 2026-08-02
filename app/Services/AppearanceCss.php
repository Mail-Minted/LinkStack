<?php

namespace App\Services;

use App\Http\Controllers\AppearanceController;

/**
 * Builds the per-user appearance override CSS from a SPARSE
 * customization blob (only the knobs the user changed off their theme
 * — see THEME-APPEARANCE-PLAN.md Phase 2).
 *
 * Single source of truth for override CSS: the public bio page renders
 * it via linkstack/modules/appearance-override.blade.php, and the
 * studio live preview fetches it from AppearanceController::previewCss
 * — so what you preview is byte-for-byte what visitors get.
 *
 * Legacy full blobs (every key present, from the pre-sparse model)
 * emit every rule and reproduce the old all-or-nothing output.
 *
 * Every interpolated value is sanitized here (hex guards, whitelists,
 * URL pattern) because previewCss feeds this builder raw request
 * input; nothing may be able to break out of the <style> tag.
 */
class AppearanceCss
{
    /** Values a knob may hold, for everything that isn't a hex/URL. */
    private const ENUMS = [
        'background.type'               => ['solid', 'gradient', 'image'],
        'background.gradient_direction' => ['to bottom', 'to right', 'to bottom right'],
        'buttons.shape'                 => ['pill', 'rounded', 'square'],
        'buttons.style'                 => ['filled', 'outline', 'soft'],
        'avatar.shape'                  => ['circle', 'rounded_square', 'off'],
        'avatar.backdrop'               => ['theme', 'none'],
        'social_icons.color'            => ['auto', 'brand', 'custom'],
        'social_icons.size'             => ['small', 'medium', 'large', 'xl'],
        'social_icons.spacing'          => ['tight', 'normal', 'loose'],
        'social_icons.background_style' => ['none', 'circle', 'rounded', 'solid'],
        'social_icons.hover'            => ['none', 'lift', 'glow', 'scale', 'colorshift'],
    ];

    /**
     * The Google Fonts stylesheet URL when the sparse blob overrides
     * the font, else null (theme's own self-hosted font applies).
     */
    public static function fontHref(array $sparse): ?string
    {
        $font = data_get($sparse, 'typography.font');
        if (!is_string($font) || $font === '' || !in_array($font, AppearanceController::GOOGLE_FONTS, true)) {
            return null;
        }
        return 'https://fonts.googleapis.com/css2?family='
            . str_replace('%20', '+', rawurlencode($font))
            . ':wght@400;500;600;700&display=swap';
    }

    /**
     * Override CSS for the sparse blob. Empty string when nothing is
     * overridden. $manifest supplies effective values where a rule
     * needs a knob the user did NOT override (e.g. button color when
     * only the shape changed).
     */
    public static function build(array $sparse, array $manifest): string
    {
        if (empty($sparse)) {
            return '';
        }

        $has = fn(string $path) => data_get($sparse, $path) !== null;
        // effective = user's override where present, else the theme's value
        $eff = fn(string $path) => data_get($sparse, $path) ?? data_get($manifest, $path);

        $rules = [];

        // ----- resolved + sanitized values ------------------------------
        $primary   = self::hex($eff('colors.primary'), '#3b82f6');
        $textColor = self::hex($eff('colors.text'), '#111111');
        $btnText   = self::hex($eff('colors.button_text'), '#ffffff');
        $bgFall    = self::hex($eff('colors.background'), '#ffffff');
        $font      = self::fontHref($sparse) ? data_get($sparse, 'typography.font') : null;

        // ----- :root vars (informational; only overridden keys) ---------
        $vars = [];
        if ($has('colors.primary'))     $vars[] = "--user-primary: {$primary};";
        if ($has('colors.background'))  $vars[] = "--user-bg: {$bgFall};";
        if ($has('colors.text'))        $vars[] = "--user-text: {$textColor};";
        if ($has('colors.button_text')) $vars[] = "--user-button-text: {$btnText};";
        if ($vars) {
            $rules[] = ':root { ' . implode(' ', $vars) . ' }';
        }

        // A custom background must pin the text color even when the
        // user never touched it: the stock (no-theme) stylesheet swaps
        // all text between black and white with the VIEWER's OS color
        // scheme, so an untouched text color over a user-chosen
        // background renders differently per visitor (white on a light
        // background for dark-mode viewers). Pinning the effective
        // value is a no-op on generated themes — their CSS already
        // hardcodes the same manifest color.
        $pinText = $has('colors.text') || $has('background.type');

        // ----- body: background + text color + font ---------------------
        $body = [];
        if ($has('background.type')) {
            $body[] = self::backgroundCss($sparse['background'], $bgFall);
        }
        if ($pinText) {
            $body[] = "color: {$textColor} !important;";
        }
        if ($font) {
            $body[] = "font-family: '{$font}', -apple-system, BlinkMacSystemFont, sans-serif !important;";
        }
        if ($body) {
            $rules[] = 'body { ' . implode(' ', $body) . ' }';
        }

        // Headings + description follow the text color so they read
        // correctly on whatever background was picked.
        if ($pinText) {
            $rules[] = ".header-name, .header-description, h1, h2, h3, h4, h5, h6, p { color: {$textColor} !important; }";
        }
        // Same scheme-flip applies to the stock social-icon color; pin
        // it only when the user hasn't chosen an icon color mode of
        // their own (those rules are emitted below and must keep
        // winning).
        if ($has('background.type') && !$has('social_icons.color')) {
            $rules[] = ".social-icon { color: {$textColor} !important; }";
        }

        // ----- buttons ---------------------------------------------------
        $btnSelectors = '.button, .button-custom, .button-custom_website, a.button, .button-default';
        $btn = [];
        if ($has('buttons.style')) {
            // Style change = the user deliberately replaced the theme's
            // button treatment; emit the full block.
            $btn[] = self::buttonStyleCss(self::enum('buttons.style', $eff('buttons.style'), 'filled'), $primary, $btnText);
        } else {
            // Untouched style: the theme's real treatment (accent
            // side-bars etc.) stays. Recolor only what was changed,
            // through the lens of the theme's own style.
            $effStyle = self::enum('buttons.style', $eff('buttons.style'), 'filled');
            if ($has('colors.primary')) {
                $btn[] = match ($effStyle) {
                    'outline' => "color: {$primary} !important; border-color: {$primary} !important;",
                    'soft'    => 'background-color: ' . self::rgba($primary, 0.15) . " !important; color: {$primary} !important;",
                    default   => "background-color: {$primary} !important; border-color: {$primary} !important;",
                };
            }
            if ($has('colors.button_text') && $effStyle === 'filled') {
                $btn[] = "color: {$btnText} !important;";
            }
        }
        if ($has('buttons.shape')) {
            $radius = ['pill' => '999px', 'rounded' => '10px', 'square' => '0'][self::enum('buttons.shape', $sparse['buttons']['shape'], 'rounded')];
            $btn[] = "border-radius: {$radius} !important;";
        }
        if ($btn) {
            $rules[] = "{$btnSelectors} { " . implode(' ', $btn) . ' }';
            $hoverSelectors = '.button:hover, .button-custom:hover, .button-custom_website:hover, a.button:hover, .button-default:hover';
            $rules[] = "{$hoverSelectors} { filter: brightness(0.92); }";
        }

        // ----- avatar ----------------------------------------------------
        if ($has('avatar.shape')) {
            $shape = self::enum('avatar.shape', $sparse['avatar']['shape'], 'circle');
            if ($shape === 'off') {
                // visibility (not display) keeps the layout slot so
                // blocks below don't jump up.
                $rules[] = '#avatar { visibility: hidden !important; }';
            } else {
                $radius = $shape === 'rounded_square' ? '14px' : '50%';
                $rules[] = "#avatar, .rounded-avatar, img.rounded-avatar { border-radius: {$radius} !important; }";
            }
        }
        if ($has('avatar.backdrop')
            && self::enum('avatar.backdrop', $sparse['avatar']['backdrop'], 'theme') === 'none') {
            // Generated themes paint a disc behind the photo
            // (background-color + ring shadow, both !important in the
            // theme css) — 'none' strips both so a transparent avatar
            // is genuinely see-through against the page background.
            $rules[] = '#avatar, .rounded-avatar, img.rounded-avatar { background-color: transparent !important; box-shadow: none !important; }';
        }

        // ----- social icons ----------------------------------------------
        $rules = array_merge($rules, self::socialIconRules($sparse, $has, $eff));

        return implode("\n", $rules);
    }

    /** Social-icon rules; split out for readability. */
    private static function socialIconRules(array $sparse, \Closure $has, \Closure $eff): array
    {
        $any = $has('social_icons.color') || $has('social_icons.color_custom')
            || $has('social_icons.size') || $has('social_icons.spacing')
            || $has('social_icons.background_style') || $has('social_icons.hover');
        if (!$any) {
            return [];
        }

        $sizes    = ['small' => 22, 'medium' => 30, 'large' => 38, 'xl' => 46];
        $spacings = ['tight' => 4, 'normal' => 10, 'loose' => 18];
        $size = $sizes[self::enum('social_icons.size', $eff('social_icons.size'), 'medium')];
        $gap  = $spacings[self::enum('social_icons.spacing', $eff('social_icons.spacing'), 'normal')];

        $rules = [];

        // Base layout — needed by every social rule below.
        $rules[] = '.social-link { display: inline-flex; align-items: center; justify-content: center; }';

        $icon = [];
        if ($has('social_icons.size')) {
            $icon[] = "font-size: {$size}px !important;";
        }
        if ($has('social_icons.spacing')) {
            $icon[] = 'padding: ' . max((int) round($gap / 2), 2) . 'px !important;';
        }
        if ($has('social_icons.hover')) {
            $icon[] = 'transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, color 0.18s ease !important;';
        }
        if ($icon) {
            $rules[] = '.social-icon { ' . implode(' ', $icon) . ' }';
        }
        if ($has('social_icons.spacing')) {
            $rules[] = ".social-icon-div { gap: {$gap}px; padding-bottom: 30px; }";
        }

        $bgStyle = self::enum('social_icons.background_style', $eff('social_icons.background_style'), 'none');
        if ($has('social_icons.background_style') && $bgStyle !== 'none') {
            $chip = $size + 24;
            if ($bgStyle === 'solid') {
                // Filled brand circle — per-brand backgrounds below.
                $rules[] = ".social-link { background: #555; color: #fff !important; border-radius: 50%; padding: 6px; width: {$chip}px; height: {$chip}px; }";
                $rules[] = '.social-link .social-icon { color: #fff !important; }';
            } else {
                $radius = $bgStyle === 'circle' ? '50%' : '12px';
                $rules[] = ".social-link { background: rgba(128, 128, 128, 0.12); border-radius: {$radius}; padding: 6px; width: {$chip}px; height: {$chip}px; }";
            }
        }

        $colorMode = self::enum('social_icons.color', $eff('social_icons.color'), 'auto');
        if ($has('social_icons.color') && $colorMode === 'custom') {
            $custom = self::hex($eff('social_icons.color_custom'), '#111111');
            $rules[] = ".social-icon, .social-link .social-icon { color: {$custom} !important; }";
        }

        // Per-brand rules drive glyph color in Brand mode OR chip
        // background in Solid mode.
        $brandGlyphs = $has('social_icons.color') && $colorMode === 'brand' && $bgStyle !== 'solid';
        $brandChips  = $has('social_icons.background_style') && $bgStyle === 'solid';
        if ($brandGlyphs || $brandChips) {
            foreach (AppearanceController::BRAND_COLORS as $brand => $hex) {
                if ($brandGlyphs) {
                    $rules[] = ".social-icon.fa-{$brand} { color: {$hex} !important; }";
                }
                if ($brandChips) {
                    $rules[] = ".social-link:has(.social-icon.fa-{$brand}) { background: {$hex} !important; }";
                }
            }
        }

        if ($has('social_icons.hover')) {
            $hover = self::enum('social_icons.hover', $sparse['social_icons']['hover'], 'none');
            $rules[] = match ($hover) {
                'lift'       => '.social-link:hover { transform: translateY(-3px); }',
                'glow'       => '.social-link:hover { box-shadow: 0 0 14px rgba(var(--user-primary-rgb, 59, 130, 246), 0.5); }',
                'scale'      => '.social-link:hover { transform: scale(1.15); }',
                'colorshift' => '.social-link:hover .social-icon { filter: hue-rotate(45deg) saturate(1.3); }',
                default      => '',
            };
            $rules = array_filter($rules, fn($r) => $r !== '');
        }

        return array_values($rules);
    }

    /**
     * body background declaration with all longhand props explicit —
     * shorthand would let stale longhands from other override layers
     * stick around in the cascade.
     */
    private static function backgroundCss(array $bg, string $fallbackColor): string
    {
        $type = self::enum('background.type', $bg['type'] ?? 'solid', 'solid');

        if ($type === 'gradient') {
            $dir   = self::enum('background.gradient_direction', $bg['gradient_direction'] ?? '', 'to bottom');
            $start = self::hex($bg['gradient_start'] ?? null, '#3b82f6');
            $end   = self::hex($bg['gradient_end'] ?? null, '#8b5cf6');
            return "background-image: linear-gradient({$dir}, {$start}, {$end}) !important;"
                . ' background-color: transparent !important;'
                . ' background-size: auto !important;'
                . ' background-position: 0% 0% !important;'
                . ' background-repeat: no-repeat !important;'
                . ' background-attachment: fixed !important;';
        }

        if ($type === 'image') {
            $url = self::imageUrl($bg['image_url'] ?? '');
            if ($url !== null) {
                return "background-image: url('{$url}') !important;"
                    . ' background-size: cover !important;'
                    . ' background-position: center !important;'
                    . ' background-repeat: no-repeat !important;'
                    . ' background-attachment: fixed !important;'
                    . " background-color: {$fallbackColor} !important;";
            }
            return "background-image: none !important; background-color: {$fallbackColor} !important;";
        }

        $solid = self::hex($bg['solid'] ?? null, $fallbackColor);
        return 'background-image: none !important;'
            . " background-color: {$solid} !important;"
            . ' background-size: auto !important;'
            . ' background-position: 0% 0% !important;'
            . ' background-repeat: repeat !important;'
            . ' background-attachment: scroll !important;';
    }

    private static function buttonStyleCss(string $style, string $primary, string $btnText): string
    {
        return match ($style) {
            'outline' => "background-color: transparent !important; background-image: none !important; color: {$primary} !important; border: 2px solid {$primary} !important;",
            'soft'    => 'background-color: ' . self::rgba($primary, 0.15) . " !important; background-image: none !important; color: {$primary} !important; border: 2px solid transparent !important;",
            default   => "background-color: {$primary} !important; background-image: none !important; color: {$btnText} !important; border: 2px solid {$primary} !important;",
        };
    }

    // ----- sanitizers ----------------------------------------------------

    private static function hex($v, string $fallback): string
    {
        return (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : $fallback;
    }

    private static function enum(string $key, $v, string $fallback): string
    {
        return (is_string($v) && in_array($v, self::ENUMS[$key], true)) ? $v : $fallback;
    }

    /**
     * Accept http(s) URLs, uploaded backgrounds, or theme-shipped photo
     * paths; reject anything that could escape the url('...') literal.
     */
    private static function imageUrl($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        if (!preg_match('#^(https?://|/assets/img/background-img/|/themes/)[^\'"<>\\\\\s]+$#i', $v)) {
            return null;
        }
        return $v;
    }

    private static function rgba(string $hex, float $alpha): string
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }
}
