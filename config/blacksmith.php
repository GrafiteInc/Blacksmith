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
    | Where blueprints and state are stored
    |--------------------------------------------------------------------------
    */
    'path' => base_path('.blacksmith'),

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
    ],

    /*
    |--------------------------------------------------------------------------
    | SSH access used to run the Forge provision command on new servers
    |--------------------------------------------------------------------------
    */
    'ssh' => [
        'user' => env('BLACKSMITH_SSH_USER', 'root'),
        'key' => env('BLACKSMITH_SSH_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling (seconds)
    |--------------------------------------------------------------------------
    */
    'poll_interval' => 15,
    'provision_timeout' => 1800,

    /*
    |--------------------------------------------------------------------------
    | Server Provider
    |--------------------------------------------------------------------------
    |
    | These are the defaults for new servers, a server profile in
    | .blacksmith/servers/{profile}.json can override them via "provider".
    |
    */
    'server_provider' => env('BLACKSMITH_SERVER_PROVIDER', 'digitalocean'),
    'server_provider_token' => env('BLACKSMITH_SERVER_PROVIDER_TOKEN'),
    'server_provider_options' => [
        'digitalocean' => [
            'size' => env('BLACKSMITH_DO_SIZE', 's-1vcpu-2gb-amd'),
            'region' => env('BLACKSMITH_DO_REGION', 'tor1'),
            'image' => env('BLACKSMITH_DO_IMAGE', 'ubuntu-24-04-x64'),
            'vpc_uuid' => env('BLACKSMITH_DO_VPC_UUID', false),
            'backups' => env('BLACKSMITH_DO_BACKUPS', false),
            'ipv6' => env('BLACKSMITH_DO_IPV6', false),
            'monitoring' => env('BLACKSMITH_DO_MONITORING', true),
        ],
    ],
];
