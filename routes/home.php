<?php
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

Route::middleware('disableCookies')->group(function () {

$customHomeUrl = config('advanced-config.custom_home_url', '/home');
$disableHomePageConfig = config('advanced-config.disable_home_page');
$redirectHomePageConfig = config('advanced-config.redirect_home_page');

// '/' is a REQUEST-TIME dispatcher. The old version branched on
// request()->getHost() while REGISTERING routes, which only works when
// the app boots per request (php-fpm) — under tests, route caching, or
// any long-lived runtime the host is frozen at boot and every custom
// domain silently falls through to the home page. Deciding inside the
// route callback makes host mapping correct everywhere.
Route::get('/', function () use ($disableHomePageConfig, $redirectHomePageConfig) {
    $request = app('request');
    $host = strtolower($request->getHost());

    // Mail Minted per-domain routing: serve the bio page of the user
    // whose `custom_domain` matches the incoming host. Guarded behind
    // a Schema check so the lookup is skipped on a fresh install
    // (before the migration that adds the column has run).
    // custom_domain stores the apex, but customer zones also ship a
    // www CNAME — normalize the leading www so both hosts land on the
    // same page.
    if (Schema::hasColumn('users', 'custom_domain')) {
        $lookupHost = preg_replace('/^www\./', '', $host);
        $mappedUser = User::where('custom_domain', $lookupHost)->first();
        if ($mappedUser) {
            $request->merge(['littlelink' => $mappedUser->littlelink_name]);
            return app(UserController::class)->littlelink($request);
        }
    }

    // Upstream custom_domains config-file fallback (unchanged behavior).
    foreach (config('advanced-config.custom_domains', []) as $config) {
        if ($host == $config['domain']) {
            $request->merge(['littlelink' => isset($config['name']) ? $config['name'] : $config['id']]);
            if (isset($config['id'])) {
                $request->merge(['useif' => 'true']);
            }
            return app(UserController::class)->littlelink($request);
        }
    }

    // No host mapping — original home-page behavior.
    if (env('HOME_URL') != '') {
        return app(UserController::class)->littlelinkhome($request);
    }
    if ($disableHomePageConfig == 'redirect') {
        return redirect($redirectHomePageConfig);
    }
    if ($disableHomePageConfig != 'true') {
        return app(HomeController::class)->home();
    }
    abort(404);
})->name('littlelink');

// When HOME_URL claims '/', the classic home page moves to
// custom_home_url (unchanged env-dependent registration — env/config
// are static per deploy, so registration-time branching is fine here).
if (env('HOME_URL') != '') {
    if ($disableHomePageConfig == 'redirect') {
        Route::get($customHomeUrl, function () use ($redirectHomePageConfig) {
            return redirect($redirectHomePageConfig);
        });
    } elseif ($disableHomePageConfig != 'true') {
        Route::get($customHomeUrl, [HomeController::class, 'home'])->name('home');
    }
}

});
