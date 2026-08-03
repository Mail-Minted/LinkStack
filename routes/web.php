<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AdminController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\LinkTypeViewController;
use App\Http\Controllers\ContactFormController;
use App\Http\Controllers\NewsletterSignupController;
use App\Http\Controllers\StripeConnectController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\InstallerController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Prevents section below from being run by 'composer update'
if(file_exists(base_path('storage/app/ISINSTALLED'))){
  // generates new APP KEY if no one is set
  if(EnvEditor::getKey('APP_KEY')==''){try{Artisan::call('key:generate');} catch (exception $e) {}}
 
  // copies template meta config if none is present
  if(!file_exists(base_path("config/advanced-config.php"))){copy(base_path('storage/templates/advanced-config.php'), base_path('config/advanced-config.php'));}
 }

 // Installer
if(file_exists(base_path('INSTALLING')) or file_exists(base_path('INSTALLERLOCK'))){

  Route::get('/', [InstallerController::class, 'showInstaller'])->name('showInstaller');
  Route::post('/create-admin', [InstallerController::class, 'createAdmin'])->name('createAdmin');
  Route::post('/db', [InstallerController::class, 'db'])->name('db');
  Route::post('/mysql', [InstallerController::class, 'mysql'])->name('mysql');
  Route::post('/options', [InstallerController::class, 'options'])->name('options');
  Route::get('/mysql-test', [InstallerController::class, 'mysqlTest'])->name('mysqlTest');
  Route::get('/skip', function () {Artisan::call('db:seed', ['--class' => 'AdminSeeder',]); Auth::login(User::where('name', 'admin')->first()); return redirect(url('dashboard'));});
  Route::post('/editConfigInstaller', [InstallerController::class, 'editConfigInstaller'])->name('editConfigInstaller');

  Route::get('{any}', function() {
    if(!DB::table('users')->get()->isEmpty()){
      // If users exist, the install is done — but the route cache baked in
      // "installer mode" during build (when INSTALLING existed). Drop the
      // cached route file and invalidate opcache so the next request
      // re-parses web.php and registers the normal (non-installer) routes.
      foreach (glob(base_path('bootstrap/cache/routes*.php')) ?: [] as $cached) {
        if (function_exists('opcache_invalidate')) {
          opcache_invalidate($cached, true);
        }
        @unlink($cached);
      }
      // Also clear the INSTALLING marker if it's still lying around from
      // a half-finished install.
      if (file_exists(base_path('INSTALLING'))) {
        @unlink(base_path('INSTALLING'));
      }
      return redirect(url('/'));
    } else {
      return redirect(url(''));
    }
  })->where('any', '.*');

}else{

// Disables routes if in Maintenance Mode
if(env('MAINTENANCE_MODE') != 'true'){

require __DIR__.'/home.php';

//Redirect if no page URL is set
Route::get('/@', function () {
    return redirect('/studio/no_page_name');
});

//Show diagnose page
Route::get('/panel/diagnose', function () {
        return view('panel/diagnose', []);
});

//Public route
$custom_prefix = config('advanced-config.custom_url_prefix');
Route::get('/going/{id?}', [UserController::class, 'clickNumber'])->where('link', '.*')->name('clickNumber')->middleware('disableCookies');
Route::get('/info/{id?}', [AdminController::class, 'redirectInfo'])->name('redirectInfo');
if($custom_prefix != ""){Route::get('/' . $custom_prefix . '{littlelink}', [UserController::class, 'littlelink'])->name('littlelink');}
Route::get('/@{littlelink}', [UserController::class, 'littlelink'])->name('littlelink')->middleware('disableCookies');
Route::get('/pages/'.strtolower(footer('Terms')), [AdminController::class, 'pagesTerms'])->name('pagesTerms')->middleware('disableCookies');
Route::get('/pages/'.strtolower(footer('Privacy')), [AdminController::class, 'pagesPrivacy'])->name('pagesPrivacy')->middleware('disableCookies');
Route::get('/pages/'.strtolower(footer('Contact')), [AdminController::class, 'pagesContact'])->name('pagesContact')->middleware('disableCookies');
Route::get('/theme/@{littlelink}', [UserController::class, 'theme'])->name('theme');
Route::get('/vcard/{id?}', [UserController::class, 'vcard'])->name('vcard');
Route::get('/u/{id?}', [UserController::class, 'userRedirect'])->name('userRedirect');

Route::get('/report', function () {return view('report');});
Route::post('/report', [UserController::class, 'report'])->name('report');

Route::get('/demo-page', [App\Http\Controllers\HomeController::class, 'demo'])->name('demo')->middleware('disableCookies');

Route::get('/block-asset/{type}', [LinkTypeViewController::class, 'blockAsset'])
  ->name('block.asset')->where(['type' => '[a-zA-Z0-9_-]+']);

// ==== Custom block: public form submissions ====
// Each block that accepts visitor input has its POST endpoint here.
// Throttle protects against bot-driven abuse; validation + business
// logic live in the respective controllers.

// CSP violation collector (browsers POST report JSON here; see the
// report-uri in App\Http\Middleware\Headers). CSRF-exempt — the browser
// can't attach a token — and throttled so it can't be used to flood the
// log. Report-Only on studio/other pages routes Phase-2 violations here.
Route::post('/csp-report', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Log::warning('CSP report', [
        'body' => mb_substr((string) $request->getContent(), 0, 2000),
    ]);
    return response()->noContent();
})->name('cspReport')->middleware('throttle:60,1');

Route::post('/contact-form/{id}/submit', [ContactFormController::class, 'submit'])
  ->name('contactFormSubmit')
  ->where(['id' => '[0-9]+'])
  ->middleware('throttle:5,1');

Route::post('/newsletter/{id}/subscribe', [NewsletterSignupController::class, 'subscribe'])
  ->name('newsletterSubscribe')
  ->where(['id' => '[0-9]+'])
  ->middleware('throttle:5,1');

Route::post('/stripe/checkout/{id}', [StripePaymentController::class, 'checkout'])
  ->name('stripePaymentCheckout')
  ->where(['id' => '[0-9]+'])
  ->middleware('throttle:20,1');

// ==== Stripe webhook (Stripe → us) ====
// Signature verified in the controller via STRIPE_WEBHOOK_SECRET.
// CSRF excluded in app/Http/Middleware/VerifyCsrfToken.php.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
  ->name('stripe.webhook');

}

Route::middleware(['auth', 'blocked', 'impersonate'])->group(function () {
//User route
Route::group([
    'middleware' => env('REGISTER_AUTH'),
], function () {
if(env('FORCE_ROUTE_HTTPS') == 'true'){URL::forceScheme('https');}
if(isset($_COOKIE['LinkCount'])){if($_COOKIE['LinkCount'] == '20'){$LinkPage = 'showLinks20';}elseif($_COOKIE['LinkCount'] == '30'){$LinkPage = 'showLinks30';}elseif($_COOKIE['LinkCount'] == 'all'){$LinkPage = 'showLinksAll';} else {$LinkPage = 'showLinks';}} else {$LinkPage = 'showLinks';} //Shows correct link number
Route::get('/dashboard', [AdminController::class, 'index'])->name('panelIndex');
Route::get('/studio/index', function(){return redirect(url('dashboard'));});
// Unified studio editor — consolidates page / appearance / social-icons
// / links into one tabbed page. The old GET routes below redirect into
// the matching tab (see further down); the POST endpoints are unchanged.
Route::get('/studio/edit', [UserController::class, 'showEditor'])->name('showEditor');
// Draft/publish: promote the draft (live DB) to the published snapshot,
// or discard the draft back to what's published.
Route::post('/studio/publish', [UserController::class, 'publish'])->name('publish');
Route::post('/studio/discard', [UserController::class, 'discard'])->name('discard');
Route::get('/studio/add-link', [UserController::class, 'AddUpdateLink'])->name('showButtons');
Route::post('/studio/edit-link', [UserController::class, 'saveLink'])->name('addLink');
Route::post('/studio/block-preview', [UserController::class, 'blockPreview'])->name('blockPreview');
Route::get('/studio/edit-link/{id}', [UserController::class, 'AddUpdateLink'])->name('showLink')->middleware('link-id');
Route::post('/studio/sort-link', [UserController::class, 'sortLinks'])->name('sortLinks');
// Old studio pages now redirect into the matching tab of the unified
// /studio/edit editor. Route names are preserved so route('showLinks'),
// route('showSocialIcons'), etc. still resolve everywhere they're used.
Route::get('/studio/links', fn() => redirect('/studio/edit#blocks'))->name($LinkPage);
Route::get('/studio/social-icons', fn() => redirect('/studio/edit#social'))->name('showSocialIcons');
Route::post('/studio/social-icons/reorder', [UserController::class, 'reorderSocialIcons'])->name('reorderSocialIcons');
// Theme SELECTION moved into the unified editor's Themes tab (item 12a).
// /studio/theme now serves ONLY the admin theme-zip management surface:
// admins render the page (which carries "Manage themes"); everyone else
// is redirected to the editor's Appearance tab (which hosts the theme
// gallery). The POST (editTheme) still handles both customer
// theme-select and admin upload.
Route::get('/studio/theme', function () {
    return (auth()->check() && auth()->user()->role === 'admin')
        ? app(UserController::class)->showTheme(request())
        : redirect('/studio/edit#appearance');
})->name('showTheme');
Route::post('/studio/theme', [UserController::class, 'editTheme'])->name('editTheme');
Route::get('/deleteLink/{id}', [UserController::class, 'deleteLink'])->name('deleteLink')->middleware('link-id');
Route::get('/upLink/{up}/{id}', [UserController::class, 'upLink'])->name('upLink')->middleware('link-id');
Route::post('/studio/edit-link/{id}', [UserController::class, 'editLink'])->name('editLink')->middleware('link-id');
// Legacy /studio/button-editor routes removed: the per-block CSS editor
// was replaced by the Appearance section of the unified /studio/edit
// editor (Pass 3). Its view, showCSS, and editCSS methods are gone.
Route::get('/studio/page', fn() => redirect('/studio/edit#basics'))->name('showPage');
Route::get('/studio/no_page_name', fn() => redirect('/studio/edit#basics'));
Route::post('/studio/page', [UserController::class, 'editPage'])->name('editPage');
Route::post('/studio/background', [UserController::class, 'themeBackground'])->name('themeBackground');
Route::get('/studio/rem-background', [UserController::class, 'removeBackground'])->name('removeBackground');
Route::get('/studio/profile', [UserController::class, 'showProfile'])->name('showProfile');
Route::post('/studio/profile', [UserController::class, 'editProfile'])->name('editProfile');
Route::post('/studio/profile/analytics', [UserController::class, 'editAnalytics'])->name('editAnalytics');

// Live-preview Appearance editor (colors, background, typography, button + avatar shape).
Route::get('/studio/appearance',        fn() => redirect('/studio/edit#appearance'))->name('showAppearance');
Route::post('/studio/appearance',       [AppearanceController::class, 'save'])->name('saveAppearance');
Route::post('/studio/appearance/reset', [AppearanceController::class, 'reset'])->name('resetAppearance');
// Live-preview CSS: the editor posts the form's unsaved state and gets
// back the exact override CSS a save would publish (sparse-diffed
// against the theme manifest server-side — single source of truth).
Route::post('/studio/appearance/preview-css', [AppearanceController::class, 'previewCss'])->name('previewAppearanceCss');
Route::post('/studio/appearance/background-image',        [AppearanceController::class, 'uploadBackgroundImage'])->name('uploadBackgroundImage');
Route::post('/studio/appearance/background-image/remove', [AppearanceController::class, 'removeBackgroundImage'])->name('removeBackgroundImage');

// ==== Stripe Connect OAuth onboarding (auth-scoped) ====
Route::get('/stripe/connect', [StripeConnectController::class, 'connect'])->name('stripe.connect');
Route::get('/stripe/connect/callback', [StripeConnectController::class, 'callback'])->name('stripe.connect.callback');
Route::get('/stripe/status', [StripeConnectController::class, 'status'])->name('stripe.status');
Route::post('/stripe/disconnect', [StripeConnectController::class, 'disconnect'])->name('stripe.disconnect');
Route::post('/edit-icons', [UserController::class, 'editIcons'])->name('editIcons');
Route::get('/clearIcon/{id}', [UserController::class, 'clearIcon'])->name('clearIcon')->middleware('link-id');
Route::get('/studio/page/delprofilepicture', [UserController::class, 'delProfilePicture'])->name('delProfilePicture');
Route::post('/studio/profile-picture', [UserController::class, 'editProfilePicture'])->name('editProfilePicture');
// Custom browser-tab icon (favicon) for the user's public page — white-label branding.
Route::post('/studio/favicon', [UserController::class, 'editFavicon'])->name('editFavicon');
Route::get('/studio/rem-favicon', [UserController::class, 'removeFavicon'])->name('removeFavicon');
// Self-serve /studio/delete-user removed — see UserController note.
// Account deletion is admin/deprovision-only.
Route::post('/auth-as', [AdminController::class, 'authAs'])->name('authAs');

// Catch all redirects
Route::get('/admin/users/all', fn() => redirect(route('showUsers')));
Route::get('/studio', fn() => redirect(url('dashboard')));
Route::get('/studio/edit-link', fn() => redirect(url('dashboard')));

if(env('ALLOW_USER_EXPORT') != false){
  Route::get('/export-links', [UserController::class, 'exportLinks'])->name('exportLinks');
  Route::get('/export-all', [UserController::class, 'exportAll'])->name('exportAll');
}
if(env('ALLOW_USER_IMPORT') != false){
  Route::post('/import-data', [UserController::class, 'importData'])->name('importData');
}
Route::get('/studio/linkparamform_part/{typeid}/{linkid}', [LinkTypeViewController::class, 'getParamForm'])->name('linkparamform.part');
});
});
}

// Social login — DISABLED. Accounts are provisioned exclusively by
// Mail Minted's checkout (see routes/auth.php for the same treatment of
// register / password reset). SocialLoginController::redirectToProvider
// would auto-create a brand-new LinkStack user + bio page for anyone who
// authenticates with a social provider, bypassing the domain purchase —
// the moment a provider's OAuth credentials are ever set. We dead-end both
// routes to 404 so enabling a provider (via env or the admin UI) can never
// silently reopen public signup. Route name 'social.redirect' is kept
// registered so any view partial calling route('social.redirect') resolves.
$socialDead = function () { abort(404); };
Route::get('/social-auth/{provider}/callback', $socialDead);
Route::get('/social-auth/{provider}', $socialDead)->name('social.redirect');

Route::middleware(['auth', 'blocked', 'impersonate'])->group(function () {
//Admin route
Route::group([
    'middleware' => 'admin',
], function () {
    if(env('FORCE_ROUTE_HTTPS') == 'true'){URL::forceScheme('https');}
    Route::get('/panel/index', function(){return redirect(url('dashboard'));});
    Route::get('/admin/users', [AdminController::class, 'users'])->name('showUsers');
    Route::get('/admin/links/{id}', [AdminController::class, 'showLinksUser'])->name('showLinksUser');
    Route::get('/admin/deleteLink/{id}', [AdminController::class, 'deleteLinkUser'])->name('deleteLinkUser');
    Route::get('/admin/users/block/{block}/{id}', [AdminController::class, 'blockUser'])->name('blockUser');
    Route::get('/admin/users/verify/{verify}/{id}', [AdminController::class, 'verifyCheckUser'])->name('verifyCheckUser');
    Route::get('/admin/users/verify-mail/{verify}/{id}', [AdminController::class, 'verifyUser'])->name('verifyUser');
    Route::get('/admin/edit-user/{id}', [AdminController::class, 'showUser'])->name('showUser');
    Route::post('/admin/edit-user/{id}', [AdminController::class, 'editUser'])->name('editUser');
    Route::get('/admin/new-user', [AdminController::class, 'createNewUser'])->name('createNewUser')->middleware('max.users');
    Route::get('/admin/delete-user/{id}', [AdminController::class, 'deleteUser'])->name('deleteUser');
    Route::post('/admin/delete-table-user/{id}', [AdminController::class, 'deleteTableUser'])->name('deleteTableUser');
    Route::get('/admin/pages', [AdminController::class, 'showSitePage'])->name('showSitePage');
    Route::post('/admin/pages', [AdminController::class, 'editSitePage'])->name('editSitePage');
    Route::get('/admin/advanced-config', [AdminController::class, 'showFileEditor'])->name('showFileEditor');
    Route::post('/admin/advanced-config', [AdminController::class, 'editAC'])->name('editAC');
    Route::get('/admin/env', [AdminController::class, 'showFileEditor'])->name('showFileEditor.env');
    Route::post('/admin/env', [AdminController::class, 'editENV'])->name('editENV');
    Route::get('/admin/site', [AdminController::class, 'showSite'])->name('showSite');
    Route::post('/admin/site', [AdminController::class, 'editSite'])->name('editSite');
    Route::get('/admin/site/delavatar', [AdminController::class, 'delAvatar'])->name('delAvatar');
    Route::get('/admin/site/delfavicon', [AdminController::class, 'delFavicon'])->name('delFavicon');
    Route::get('/admin/phpinfo', [AdminController::class, 'phpinfo'])->name('phpinfo');
    Route::get('/admin/backups', [AdminController::class, 'showBackups'])->name('showBackups');
    Route::post('/admin/theme', [AdminController::class, 'deleteTheme'])->name('deleteTheme');
    Route::get('/admin/theme', [AdminController::class, 'showThemes'])->name('showThemes');
    Route::get('/update/theme', [AdminController::class, 'updateThemes'])->name('updateThemes');
    Route::get('/admin/config', [AdminController::class, 'showConfig'])->name('showConfig');
    Route::post('/admin/config', [AdminController::class, 'editConfig'])->name('editConfig');
    Route::get('/send-test-email', [AdminController::class, 'SendTestMail'])->name('SendTestMail');
    Route::get('/auth-as/{id}', [AdminController::class, 'authAsID'])->name('authAsID');
    Route::get('/theme-updater', function () {return view('studio/theme-updater', []);});
    Route::get('/update', function () {return view('update', []);});
    Route::get('/backup', function () {return view('backup', []);});

    Route::group(['namespace'=>'App\Http\Controllers\Admin', 'prefix'=>'admin', 'as'=>'admin'],function() {
        //Route::resource('/admin/linktype', LinkTypeController::class);
        Route::resources([
            'linktype'=>LinkTypeController::class
        ]);
    });

}); // End Admin authenticated routes
});

// Displays Maintenance Mode page
if(env('MAINTENANCE_MODE') == 'true'){
Route::get('/{any}', function () {
  return view('maintenance');
  })->where('any', '.*');
}

require __DIR__.'/auth.php';

if(config('advanced-config.custom_url_prefix') == ""){
  Route::get('/{littlelink}', [UserController::class, 'littlelink'])->name('littlelink');
}
require __DIR__ . '/sso-mailminted.php';
