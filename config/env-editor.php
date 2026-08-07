<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Files Config
    |--------------------------------------------------------------------------
    */
    'paths' => [
        // .env file directory
        'env' => base_path(),
        // Backup files directory. These are verbatim copies of .env, so
        // they must not sit in the document root (which, in LinkStack's
        // shared-hosting layout, is the app root -- so the old 'backups'
        // was web-reachable at /backups/env_<timestamp>, matching none of
        // the .htaccess deny rules). Absolute, because the package returns
        // this value unmodified and a relative path resolves against the
        // process CWD rather than the app root.
        'backupDirectory' => storage_path('app/env-backups'),
    ],
    // .env file name
    'envFileName' => '.env',

    /*
    |--------------------------------------------------------------------------
    | Routes group config
    |--------------------------------------------------------------------------
    |
    */
    'route' => [
        // Prefix url for route Group
        'prefix' => 'env-editor',
        // Routes base name
        'name' => 'env-editor',
        // Middleware(s) applied on route Group
        'middleware' => ['web', 'admin'],
    ],

    /* ------------------------------------------------------------------------------------------------
    |  Time Format for Views and parsed backups
    | ------------------------------------------------------------------------------------------------
    */
    'timeFormat' => 'd/m/Y H:i:s',

    /* ------------------------------------------------------------------------------------------------
     | Set Views options
     | ------------------------------------------------------------------------------------------------
     | Here you can set The "extends" blade of index.blade.php
    */
    'layout' => 'env-editor::layout',

];
