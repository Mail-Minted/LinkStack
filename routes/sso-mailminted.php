<?php
/*
 * Mail Minted → LinkStack SSO bridge.
 *
 * Drop this file into the LinkStack Laravel app at:
 *   routes/sso-mailminted.php
 *
 * Then include it from routes/web.php with:
 *   require __DIR__ . '/sso-mailminted.php';
 *
 * Dependencies (composer require in the LinkStack container):
 *   firebase/php-jwt:^6.10
 *
 * Environment (add to LinkStack's .env):
 *   MAILMINTED_SSO_SHARED_SECRET=<same value as backend/.env LINKSTACK_SSO_SHARED_SECRET>
 *
 * How it works:
 *   1. Mail Minted mints a short-lived HS256 JWT (60-sec TTL) with the
 *      LinkStack user_id as `sub` and redirects the customer to
 *      /sso/mailminted?token=<JWT> on this LinkStack host.
 *   2. This route verifies the signature + issuer + audience + expiry.
 *   3. On success, it logs the matching user in via Auth::loginUsingId
 *      and redirects to /dashboard. On any failure, it redirects to
 *      /login with an error flash.
 *
 * Security notes:
 *   - Anti-replay via 60-sec TTL. A one-shot nonce table is overkill at
 *     this window and adds a DB round-trip per redirect. If a leaked
 *     token in the Referrer ever becomes a concern, add a jti/seen
 *     table here.
 *   - Use HTTPS end-to-end. The token rides in the querystring; a
 *     plaintext hop exposes it.
 *   - Rotate MAILMINTED_SSO_SHARED_SECRET by updating both sides and
 *     restarting. In-flight tokens (≤60 sec) are invalidated — that's
 *     the intended behavior.
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Models\User;

Route::get('/sso/mailminted', function (Request $request) {
    $token = $request->query('token');
    $secret = env('MAILMINTED_SSO_SHARED_SECRET');

    if (!$token || !$secret) {
        return redirect('/login')->with('error', 'Invalid SSO link.');
    }

    try {
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
    } catch (\Throwable $e) {
        Log::warning('Mail Minted SSO rejected: ' . $e->getMessage());
        return redirect('/login')->with('error', 'SSO token is invalid or expired.');
    }

    if (($decoded->iss ?? null) !== 'mailminted' || ($decoded->aud ?? null) !== 'linkstack') {
        return redirect('/login')->with('error', 'SSO token issuer mismatch.');
    }

    // firebase/php-jwt enforces `exp` only when the claim is present, so a
    // token minted without one never expires. The 60-second TTL was an
    // issuer-side convention this verifier did not actually require.
    // Tolerate a missing `iat` by bounding against now instead, so this
    // does not depend on the issuer sending both.
    $exp = $decoded->exp ?? null;
    $iat = $decoded->iat ?? null;
    $maxLifetime = 300;
    $lifetimeOk = $exp && ($iat ? ($exp - $iat) <= $maxLifetime : $exp <= (time() + $maxLifetime));
    if (!$lifetimeOk) {
        Log::warning('Mail Minted SSO rejected: missing or over-long lifetime');
        return redirect('/login')->with('error', 'SSO token is invalid or expired.');
    }

    // Anti-replay. The token rides in the query string, so it lands in
    // browser history, Referer headers and any proxy log in between; within
    // its TTL it was replayable by anyone who picked it up.
    //
    // Keyed on `jti` when the issuer sends one, otherwise on a hash of the
    // token itself -- which is already unique per mint. Deriving the key
    // this way means one-shot behaviour needs no coordinated change on the
    // Mail Minted side; requiring a jti outright would have broken every
    // existing handoff the moment this deployed.
    $jti = (is_string($decoded->jti ?? null) && $decoded->jti !== '')
        ? $decoded->jti
        : $token;
    if (!Cache::add('sso_jti_' . hash('sha256', $jti), true, now()->addMinutes(10))) {
        Log::warning('Mail Minted SSO rejected: token replay');
        return redirect('/login')->with('error', 'SSO token is invalid or expired.');
    }

    $userId = $decoded->sub ?? null;
    if (!$userId) {
        return redirect('/login')->with('error', 'SSO token missing subject.');
    }

    // Check suspension here rather than relying on the dashboard's
    // CheckBlockedUser to bounce them: LoginRequest::authenticate passes
    // 'block' => 'no' to Auth::attempt, and this path should match it
    // instead of handing a disabled account a valid session first.
    $user = User::find($userId);
    if (!$user || $user->block === 'yes') {
        Log::warning('Mail Minted SSO: no usable LinkStack user for id ' . $userId);
        return redirect('/login')->with('error', 'Account not found on this LinkStack instance.');
    }

    Auth::login($user);
    $request->session()->regenerate();
    return redirect('/dashboard');
})->name('mailminted.sso');


/*
 * SSO Logout — the mirror of the login handoff above.
 *
 * Both apps' logout buttons bounce through this endpoint so LinkStack
 * and Mail Minted's sessions clear together. The customer never ends
 * up authenticated on one side but not the other.
 *
 *   From LinkStack: sidebar logout POSTs / GETs here directly.
 *   From Mail Minted: frontend logout button redirects here with
 *     ?return=<mailminted-post-logout-url> so the browser bounces
 *     back to Mail Minted after LinkStack's session is cleared.
 *
 * Security:
 *   - No token required (users can always sign themselves out).
 *   - The return URL is validated against MAILMINTED_APP_URL to
 *     prevent open-redirect abuse. Anything not under that origin
 *     falls back to a safe default.
 *   - GET is accepted (browser redirects can't POST easily), and
 *     the operation is idempotent, so CSRF exemption is fine here.
 */
Route::get('/sso/logout', function (Request $request) {
    // Clear the Laravel session — mirror of what
    // AuthenticatedSessionController@destroy does.
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    // Validate ?return against the configured Mail Minted origin so a
    // malicious link can't turn this route into an open redirector.
    $return = $request->query('return');
    $mmUrl  = rtrim((string) env('MAILMINTED_APP_URL', ''), '/');

    if ($return && $mmUrl && str_starts_with($return, $mmUrl . '/')) {
        return redirect()->away($return);
    }

    // No return URL provided (or it didn't validate). Redirect to the
    // Mail Minted logout-complete page if we know where that lives,
    // otherwise the LinkStack login page as a last resort.
    if ($mmUrl) {
        return redirect()->away($mmUrl . '/logout-complete');
    }
    return redirect('/login');
})->name('mailminted.sso.logout');
