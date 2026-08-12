<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Version history for the bio page (instant-live model).
 *
 * The public page always renders the live DB rows — there is no draft
 * and no publish step. Safety comes from point-in-time versions in the
 * page_versions table: one is captured when an editing session starts
 * (editor load, at most every COOLDOWN_MINUTES, and only when the page
 * actually changed since the newest version), and one right before a
 * restore so a restore is itself undoable.
 *
 * A version stores the user fields + blocks in serialize()'s shape,
 * plus copies of the avatar/background files at capture time, so a
 * restore brings back the whole look — click counts are never touched.
 *
 * Replaces the draft/publish model (DRAFT-PUBLISH-PLAN.md, superseded
 * 2026-08-12); the reverse-sync in restoreTo() is the old discard().
 */
class PageVersions
{
    public const VERSION = 1;

    /** Keep at most this many versions per user. */
    public const KEEP = 20;

    /** Minimum gap between session-start captures. */
    public const COOLDOWN_MINUTES = 30;

    /** users columns the page render reads (captured per version). */
    public const USER_FIELDS = [
        'id', 'name', 'littlelink_name', 'littlelink_description',
        'theme', 'role', 'block', 'google_analytics_id', 'theme_customization',
    ];

    /**
     * Stable, render-relevant columns captured per block (+ the joined
     * button `name`). Excludes volatile columns the page never renders —
     * click_number, created_at, updated_at — so identical pages produce
     * identical snapshots and renderKey() comparisons hold.
     */
    public const BLOCK_FIELDS = [
        'id', 'user_id', 'button_id', 'link', 'title', 'type',
        'type_params', 'order', 'up_link', 'custom_css', 'custom_icon', 'name',
    ];

    /** users fields a restore writes back. Deliberately excludes the
     *  handle (public URL), role/block (admin-owned), and GA id. */
    private const RESTORE_FIELDS = ['name', 'littlelink_description', 'theme', 'theme_customization'];

    /**
     * Serialize the user's current page into the snapshot shape.
     * Returns null if the user doesn't exist.
     */
    public static function serialize($userId): ?array
    {
        $user = User::where('id', $userId)->first(self::USER_FIELDS);
        if (!$user) {
            return null;
        }

        $blocks = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->select('links.*', 'buttons.name')
            ->where('user_id', $userId)
            ->orderBy('up_link', 'asc')
            ->orderBy('order', 'asc')
            ->get()
            ->map(fn ($l) => array_intersect_key((array) $l, array_flip(self::BLOCK_FIELDS)))
            ->all();

        return [
            'v'      => self::VERSION,
            'user'   => array_intersect_key($user->getAttributes(), array_flip(self::USER_FIELDS)),
            'blocks' => $blocks,
            // 'images' is added by capture() (per-version file copies).
        ];
    }

    /** The render-driving parts of a snapshot as a comparable string
     *  (images excluded — a re-upload to the same look shouldn't force
     *  a new version by itself). */
    private static function renderKey(?array $snap): string
    {
        return json_encode([$snap['user'] ?? null, $snap['blocks'] ?? null]);
    }

    /** Newest-first version rows for the editor's History list. */
    public static function listFor($userId)
    {
        return DB::table('page_versions')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::KEEP)
            ->get(['id', 'cause', 'created_at']);
    }

    /**
     * Capture a version at the start of an editing session (editor
     * load). Skipped when the page hasn't changed since the newest
     * version, or when one was captured within the cooldown.
     */
    public static function captureIfDue($userId): void
    {
        $current = self::serialize($userId);
        if (!$current) {
            return;
        }

        $newest = DB::table('page_versions')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first(['snapshot', 'created_at']);

        if ($newest) {
            $prev = json_decode($newest->snapshot, true);
            if (self::renderKey(is_array($prev) ? $prev : null) === self::renderKey($current)) {
                return; // nothing changed since the last version
            }
            if ($newest->created_at
                && now()->parse($newest->created_at)->gt(now()->subMinutes(self::COOLDOWN_MINUTES))) {
                return; // captured recently — don't spam versions
            }
        }

        self::capture($userId, 'edit');
    }

    /**
     * Record a version of the user's CURRENT page: insert the snapshot,
     * copy the avatar/background files to per-version locations, prune
     * to KEEP. Returns the new version id (null if no such user).
     */
    public static function capture($userId, string $cause): ?int
    {
        $snapshot = self::serialize($userId);
        if (!$snapshot) {
            return null;
        }

        $id = DB::table('page_versions')->insertGetId([
            'user_id'    => $userId,
            'snapshot'   => json_encode($snapshot),
            'cause'      => $cause,
            'created_at' => now(),
        ]);

        // Copy the image files under the version id, then store their
        // paths in the row (two steps because the filenames embed $id).
        $snapshot['images'] = self::copyImages($userId, $id);
        DB::table('page_versions')->where('id', $id)
            ->update(['snapshot' => json_encode($snapshot)]);

        self::prune($userId);
        return $id;
    }

    /**
     * Restore the page to a version. Captures the current state first
     * (cause 'before-restore') so the restore is undoable, then
     * reverse-syncs the live rows and image files to the version.
     * Returns false when the version doesn't exist or isn't the user's.
     */
    public static function restore($userId, $versionId): bool
    {
        $row = DB::table('page_versions')
            ->where('user_id', $userId)->where('id', $versionId)
            ->first(['id', 'snapshot']);
        if (!$row) {
            return false;
        }
        $snap = json_decode($row->snapshot, true);
        if (!is_array($snap)) {
            return false;
        }

        // Only record the pre-restore state when it differs from the
        // newest version (restoring twice in a row shouldn't stack
        // identical safety copies).
        $newest = DB::table('page_versions')
            ->where('user_id', $userId)->orderByDesc('id')->first(['snapshot']);
        $newestArr = $newest ? json_decode($newest->snapshot, true) : null;
        if (self::renderKey(is_array($newestArr) ? $newestArr : null)
            !== self::renderKey(self::serialize($userId))) {
            self::capture($userId, 'before-restore');
        }

        self::restoreTo($userId, $snap);
        return true;
    }

    /**
     * Reverse-sync the live page to a snapshot: restore user fields,
     * drop blocks the snapshot doesn't have, upsert the snapshot's
     * blocks (content/order; click counts untouched), restore images.
     */
    private static function restoreTo($userId, array $snap): void
    {
        $fields = array_intersect_key($snap['user'] ?? [], array_flip(self::RESTORE_FIELDS));
        if (!empty($fields)) {
            User::where('id', $userId)->update($fields);
        }

        $blocks = collect($snap['blocks'] ?? []);
        $keepIds = $blocks->pluck('id')->filter()->values()->all();
        $del = DB::table('links')->where('user_id', $userId);
        if (!empty($keepIds)) {
            $del->whereNotIn('id', $keepIds);
        }
        $del->delete();

        $cols = ['id', 'button_id', 'link', 'title', 'custom_css', 'custom_icon', 'type', 'type_params', 'order', 'up_link'];
        foreach ($blocks as $b) {
            $row = array_intersect_key((array) $b, array_flip($cols));
            $row['user_id'] = $userId;
            DB::table('links')->updateOrInsert(['id' => $b['id'] ?? null], $row);
        }

        self::restoreImages($userId, $snap['images'] ?? []);
    }

    /**
     * Copy the current avatar/background to per-version locations
     * (assets/img/versions/{uid}_{vid}.ext, .../background-img/versions/
     * {uid}_{vid}.ext) and return the snapshot 'images' block. A later
     * re-upload overwrites the live files but never a version's copy.
     */
    private static function copyImages($userId, $versionId): array
    {
        $out = ['avatar' => null, 'background' => null];

        $avatar = findAvatar($userId); // 'assets/img/{file}' or 'error.error'
        if ($avatar !== 'error.error' && is_file(base_path($avatar))) {
            $ext = strtolower(pathinfo($avatar, PATHINFO_EXTENSION)) ?: 'jpg';
            if (!is_dir(base_path('assets/img/versions'))) @mkdir(base_path('assets/img/versions'), 0755, true);
            $rel = 'assets/img/versions/' . $userId . '_' . $versionId . '.' . $ext;
            @copy(base_path($avatar), base_path($rel));
            $out['avatar'] = $rel;
        }

        $bg = findBackground($userId); // '{file}' or 'error.error'
        if ($bg !== 'error.error' && is_file(base_path('assets/img/background-img/' . $bg))) {
            $ext = strtolower(pathinfo($bg, PATHINFO_EXTENSION)) ?: 'jpg';
            if (!is_dir(base_path('assets/img/background-img/versions'))) @mkdir(base_path('assets/img/background-img/versions'), 0755, true);
            // Stored as the "filename" theme.blade appends to
            // assets/img/background-img/.
            $relFile = 'versions/' . $userId . '_' . $versionId . '.' . $ext;
            @copy(base_path('assets/img/background-img/' . $bg), base_path('assets/img/background-img/' . $relFile));
            $out['background'] = $relFile;
        }

        return $out;
    }

    /** Restore the live image files from a version's copies. A version
     *  with no avatar/background restores to none — that's the look it
     *  recorded. */
    private static function restoreImages($userId, array $images): void
    {
        foreach (glob(base_path('assets/img/' . $userId . '_*')) ?: [] as $f) {
            @unlink($f);
        }
        if (!empty($images['avatar']) && is_file(base_path($images['avatar']))) {
            $ext = strtolower(pathinfo($images['avatar'], PATHINFO_EXTENSION)) ?: 'jpg';
            @copy(base_path($images['avatar']), base_path('assets/img/' . $userId . '_' . time() . '.' . $ext));
        }

        foreach (glob(base_path('assets/img/background-img/' . $userId . '_*')) ?: [] as $f) {
            @unlink($f);
        }
        if (!empty($images['background'])) {
            $pub = base_path('assets/img/background-img/' . $images['background']);
            if (is_file($pub)) {
                $ext = strtolower(pathinfo($pub, PATHINFO_EXTENSION)) ?: 'jpg';
                @copy($pub, base_path('assets/img/background-img/' . $userId . '_' . time() . '.' . $ext));
            }
        }
    }

    /** Drop versions beyond the newest KEEP, including their image
     *  copies (paths read from each pruned row's own snapshot, so the
     *  seeded pre-migration rows clean up their files too). */
    private static function prune($userId): void
    {
        $stale = DB::table('page_versions')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(self::KEEP)->take(PHP_INT_MAX)
            ->get(['id', 'snapshot']);

        foreach ($stale as $row) {
            $snap = json_decode($row->snapshot, true);
            $images = is_array($snap) ? ($snap['images'] ?? []) : [];
            if (!empty($images['avatar'])) {
                @unlink(base_path($images['avatar']));
            }
            if (!empty($images['background'])) {
                @unlink(base_path('assets/img/background-img/' . $images['background']));
            }
            DB::table('page_versions')->where('id', $row->id)->delete();
        }
    }
}
