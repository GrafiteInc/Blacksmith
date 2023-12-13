<?php

/*
|--------------------------------------------------------------------------
| Blacksmith Config
|--------------------------------------------------------------------------
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Project name, this is mainly used for backups
    |--------------------------------------------------------------------------
    */
    'project' => null,

    /*
    |--------------------------------------------------------------------------
    | Must get this token from Forge API
    |--------------------------------------------------------------------------
    */
    'forge_token' => env('BLACKSMITH_FORGE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Can be any storage driver array
    |--------------------------------------------------------------------------
    */
    'backup_storage' => [
        'driver' => 's3',
        'key' => env('BLACKSMITH_AWS_ACCESS_KEY_ID'),
        'secret' => env('BLACKSMITH_AWS_SECRET_ACCESS_KEY'),
        'region' => env('BLACKSMITH_AWS_DEFAULT_REGION'),
        'bucket' => env('BLACKSMITH_AWS_BUCKET'),
        'url' => env('BLACKSMITH_AWS_URL'),
    ]
];
