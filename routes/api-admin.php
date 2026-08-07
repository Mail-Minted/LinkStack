<?php
/*
 * Mail Minted admin API for LinkStack.
 *
 * Provisioning in Mail Minted's backend (see
 * backend/src/services/linkstack/client.js) calls these endpoints to
 * create / enable / disable / delete a LinkStack user per customer
 * domain. All endpoints require the `MAILMINTED_ADMIN_API_TOKEN` env
 * var as a bearer token.
 *
 * The bearer check is inlined into every handler because Laravel's
 * route:cache serializes closures but NOT top-level function
 * declarations — a helper function defined in this file wouldn't be
 * available at runtime inside a cached closure. Inlining is ugly but
 * reliable.
 *
 * Endpoints:
 *   POST   /api/admin/users
 *   PATCH  /api/admin/users/{id}
 *   DELETE /api/admin/users/{id}
 */

use App\Models\Link;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Dedicated throttle. These share the generic 60/min api bucket otherwise,
// which is a lot of bearer-token guesses per minute per IP against an
// endpoint that deletes a user plus all their links and uploads.
// Provisioning is low-volume, so 10/min is generous for real traffic.
Route::prefix('admin')->middleware('throttle:10,1')->group(function () {

    Route::post('/users', function (Request $request) {
        $expected = env('MAILMINTED_ADMIN_API_TOKEN');
        $header = $request->header('Authorization', '');
        $presented = Str::startsWith($header, 'Bearer ') ? substr($header, 7) : null;
        if (!$expected) return response()->json(['error' => 'admin API not configured'], 503);
        if (!$presented || !hash_equals($expected, $presented)) return response()->json(['error' => 'unauthorized'], 401);

        $email = trim((string) $request->input('email', ''));
        $username = trim((string) $request->input('username', ''));
        $customDomain = strtolower(trim((string) $request->input('custom_domain', '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'valid email required'], 400);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/i', $username)) {
            return response()->json(['error' => 'username must be alphanumeric + hyphens, 2-63 chars'], 400);
        }
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $customDomain)) {
            return response()->json(['error' => 'invalid custom_domain'], 400);
        }

        // Idempotency key is custom_domain ONLY. Matching on email or
        // littlelink_name is wrong — a returning Mail Minted customer
        // would alias every new domain's user onto their admin account
        // (which owns the same email). If username or email happens to
        // collide we'd rather fail loudly via the DB unique constraint
        // below than silently return the wrong user.
        $existing = User::where('custom_domain', $customDomain)->first();
        if ($existing) {
            return response()->json([
                'user_id' => $existing->id,
                'username' => $existing->littlelink_name,
                'email' => $existing->email,
                'custom_domain' => $existing->custom_domain,
                'reused' => true,
            ]);
        }

        // The customer never uses this password; they SSO in via JWT.
        // Still set something strong in case SSO is ever disabled.
        $user = User::create([
            'name' => $username,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(Str::random(40)),
            'littlelink_name' => $username,
            'littlelink_description' => '',
            'custom_domain' => $customDomain,
            'block' => 'no',
        ]);

        return response()->json([
            'user_id' => $user->id,
            'username' => $user->littlelink_name,
            'email' => $user->email,
            'custom_domain' => $user->custom_domain,
        ], 201);
    });

    Route::patch('/users/{id}', function (Request $request, $id) {
        $expected = env('MAILMINTED_ADMIN_API_TOKEN');
        $header = $request->header('Authorization', '');
        $presented = Str::startsWith($header, 'Bearer ') ? substr($header, 7) : null;
        if (!$expected) return response()->json(['error' => 'admin API not configured'], 503);
        if (!$presented || !hash_equals($expected, $presented)) return response()->json(['error' => 'unauthorized'], 401);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'user not found'], 404);
        }
        $state = $request->input('state');
        if (!in_array($state, ['enabled', 'disabled'], true)) {
            return response()->json(['error' => 'state must be enabled or disabled'], 400);
        }
        $user->block = $state === 'disabled' ? 'yes' : 'no';
        $user->save();
        return response()->json([
            'user_id' => $user->id,
            'state' => $state,
            'block' => $user->block,
        ]);
    });

    Route::delete('/users/{id}', function (Request $request, $id) {
        $expected = env('MAILMINTED_ADMIN_API_TOKEN');
        $header = $request->header('Authorization', '');
        $presented = Str::startsWith($header, 'Bearer ') ? substr($header, 7) : null;
        if (!$expected) return response()->json(['error' => 'admin API not configured'], 503);
        if (!$presented || !hash_equals($expected, $presented)) return response()->json(['error' => 'unauthorized'], 401);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'user not found'], 404);
        }
        // Don't allow deleting user_id=1 (installer admin) by accident.
        if ((int) $id === 1) {
            return response()->json(['error' => 'refusing to delete admin user_id=1'], 409);
        }
        // links.user_id has a FOREIGN KEY without ON DELETE CASCADE
        // (social_accounts cascades, but delete it explicitly too so
        // this doesn't depend on FK enforcement being enabled).
        DB::transaction(function () use ($user) {
            Link::where('user_id', $user->id)->delete();
            SocialAccount::where('user_id', $user->id)->delete();
            $user->delete();
        });
        // Remove the customer's uploaded avatar + background so a
        // deprovisioned (cancelled) account leaves no orphaned files.
        purge_user_uploads($id);
        return response()->json(['user_id' => (int) $id, 'deleted' => true]);
    });

});
