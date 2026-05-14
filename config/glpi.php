<?php

return [

    'glpi' => [
        'url'        => env('GLPI_URL'),
        'app_token'  => env('GLPI_APP_TOKEN'),
        'user_token' => env('GLPI_USER_TOKEN'),
    ],

    'mercator' => [
        'url'      => env('MERCATOR_URL'),
        'login'    => env('MERCATOR_LOGIN'),
        'password' => env('MERCATOR_PASSWORD'),
    ],

    'sync' => [
        'dry_run' => env('SYNC_DRY_RUN', false),
    ],

];
