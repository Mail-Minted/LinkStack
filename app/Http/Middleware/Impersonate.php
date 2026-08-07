<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Closure;

/**
 * Renders the "you are impersonating X" bar and nothing else.
 *
 * Impersonation state lives in the SESSION (impersonator_id), not in a
 * users column. The previous design stored it in users.auth_as and looked
 * it up globally -- `the admin row with auth_as set` -- which had three
 * consequences:
 *
 *   - only one impersonation could exist across the whole installation, so
 *     one admin blocked the feature for every other admin;
 *   - the state outlived the session. An admin who logged out mid-
 *     impersonation was silently re-impersonated on their next login, and
 *     because this middleware runs BEFORE the admin middleware, the
 *     now-non-admin identity failed it and was bounced off /admin/*. The
 *     exit control only rendered when the session token matched the stored
 *     one, which a fresh session never does -- so there was no way out
 *     short of editing the database;
 *   - it authenticated the exit with users.remember_token, clobbering any
 *     real remember-me cookie and putting a live credential in the DOM.
 *
 * The identity switch now happens once, in AdminController::authAsID, and
 * is undone once, in AdminController::authAs. This middleware only decides
 * whether to draw the bar, so a stale or unverifiable session simply
 * doesn't get one.
 */
class Impersonate
{
    public function handle($request, Closure $next)
    {
        $impersonatorId = $request->session()->get('impersonator_id');

        if (!$impersonatorId || !Auth::check()) {
            return $next($request);
        }

        // The session claims an impersonation; make sure it still stands up.
        // Anything that doesn't verify drops the claim rather than trusting it.
        $impersonator = User::find($impersonatorId);
        if (!$impersonator || $impersonator->role !== 'admin' || (int) $impersonator->id === (int) Auth::id()) {
            $request->session()->forget('impersonator_id');
            return $next($request);
        }

        $response = $next($request);

        // Only splice into actual HTML documents -- not JSON or downloads.
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content)) {
            return $response;
        }

        $customHtml = $this->bar($request, Auth::user());

        // preg_replace_callback, not preg_replace: "$1"/"\1" in a
        // replacement STRING are backreferences and would splice the <body>
        // tag's attributes into the output.
        $response->setContent(preg_replace_callback(
            '/<body([^>]*)>/',
            fn ($m) => '<body' . $m[1] . '>' . $customHtml,
            $content,
            1
        ));

        return $response;
    }

    /**
     * The bar itself. Every value is interpolated into a raw HTML heredoc,
     * so anything user-controlled is escaped here -- the display name is
     * set by the impersonated customer.
     */
    private function bar($request, User $impersonated): string
    {
        $dashboardUrl = url('dashboard');
        $authAsUrl = url('/auth-as');
        $csrfToken = csrf_token();
        $nonce = csp_nonce();
        $name = e($impersonated->name);

        if (file_exists(base_path(findAvatar($impersonated->id)))) {
            $avatarUrl = url(findAvatar($impersonated->id));
        } elseif (file_exists(base_path("assets/linkstack/images/") . findFile('avatar'))) {
            $avatarUrl = url("assets/linkstack/images/") . "/" . findFile('avatar');
        } else {
            $avatarUrl = asset('assets/linkstack/images/logo.svg');
        }

        return <<<EOD
<style>
  .ibar {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 67px;
    background-color: #4d4c51;
    z-index: 911;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  }

  .itext1 {
    color: white;
    font-family: "Inter", sans-serif;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 17px 16px;
  }

  .itext1 span a {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .itext1 a {
    color: white;
    text-decoration: none;
  }

  .itext1 svg {
    width: 32px;
    height: 32px;
    fill: currentColor;
    margin-left: 8px;
    margin-bottom: 4px;
  }

  .iimg {
    width: 32px;
    height: 32px;
    margin-right: 8px;
    margin-bottom: 3px;
  }

  .irounded {
    border-radius: 50%;
  }

  body {
    padding-top: 60px; /* Add padding equal to the height of .ibar */
  }
</style>

<div class="ibar">
  <p class="itext1">
    <span>
      <a href="$dashboardUrl"><img alt="avatar" class="iimg irounded" src="$avatarUrl">$name</a>
    </span>
    <a id="ibarExit" style="cursor:pointer">
      <svg xmlns="http://www.w3.org/2000/svg" class="bi bi-x" viewBox="0 0 16 16">
        <path
          d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"
        />
      </svg>
    </a>
  </p>
</div>

<form id="submitForm" action="$authAsUrl" method="POST" style="display: none;">
  <input type="hidden" name="_token" value="$csrfToken">
</form>

<script nonce="$nonce">
  // Nonced listener rather than an inline onclick: script-src is enforced
  // with no unsafe-inline on bio pages and the studio / dashboard / admin
  // area, so an inline handler is silently blocked there.
  document.getElementById('ibarExit').addEventListener('click', function () {
    document.getElementById('submitForm').submit();
  });
</script>
EOD;
    }
}
