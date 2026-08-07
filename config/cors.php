<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    // Was ['*'], which covered api/admin/* -- the bearer-authed endpoints
    // that create, disable and delete users. supports_credentials is false
    // so this was never ambient-authority CSRF, but a wildcard lets any
    // origin's JavaScript read those responses, so a leaked token becomes
    // exploitable from anywhere rather than needing a same-origin foothold.
    //
    // Mail Minted's provisioning calls are server-to-server and are not
    // subject to CORS at all, so an empty list here (MAILMINTED_APP_URL
    // unset) blocks browser cross-origin reads without affecting them.
    'allowed_origins' => array_values(array_filter([env('MAILMINTED_APP_URL')])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
