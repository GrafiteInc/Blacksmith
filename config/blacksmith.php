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
    | Forge API token and organization
    |--------------------------------------------------------------------------
    |
    | Create the token at https://forge.laravel.com/profile/api, see the
    | README for the scopes it needs. The organization is the slug from
    | your Forge URL, it can be left empty when the token only has one.
    |
    */
    'forge_token' => env('BLACKSMITH_FORGE_TOKEN'),
    'forge_organization' => env('BLACKSMITH_FORGE_ORGANIZATION'),

    // The Forge server credential (provider account) new DigitalOcean servers are created with,
    // it can be left empty when the organization has only one DigitalOcean credential.
    'forge_credential' => env('BLACKSMITH_FORGE_CREDENTIAL'),

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
        // Forge connects on this port too, it's sent as the custom server's ssh_port.
        'port' => (int) env('BLACKSMITH_SSH_PORT', 22),
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling (seconds)
    |--------------------------------------------------------------------------
    */
    'poll_interval' => 15,
    'provision_timeout' => 1800,
    // How long to wait for a deployment before a node is balanced or a cutover goes ahead
    'deploy_timeout' => 900,
    // How long cutover waits for the new server's certificate
    'certificate_timeout' => 300,

    /*
    |--------------------------------------------------------------------------
    | Server Provider
    |--------------------------------------------------------------------------
    |
    | These are the defaults for new servers, a server profile in
    | .blacksmith/servers/{profile}.json can override them via "provider".
    | Forge creates DigitalOcean servers itself, with Ubuntu from the
    | profile's ubuntu_version, in vpc_uuid or the region's default VPC.
    | The token is only used to destroy droplets from older versions.
    |
    */
    'server_provider' => env('BLACKSMITH_SERVER_PROVIDER', 'digitalocean'),
    'server_provider_token' => env('BLACKSMITH_SERVER_PROVIDER_TOKEN'),
    'server_provider_options' => [
        'digitalocean' => [
            'size' => env('BLACKSMITH_DO_SIZE', 's-1vcpu-1gb-35gb-intel'),
            'region' => env('BLACKSMITH_DO_REGION', 'tor1'),
            'image' => env('BLACKSMITH_DO_IMAGE', 'ubuntu-24-04-x64'),
            'vpc_uuid' => env('BLACKSMITH_DO_VPC_UUID', false),
            'backups' => env('BLACKSMITH_DO_BACKUPS', false),
            'ipv6' => env('BLACKSMITH_DO_IPV6', false),
            'monitoring' => env('BLACKSMITH_DO_MONITORING', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS
    |--------------------------------------------------------------------------
    |
    | Leave the provider empty to point DNS yourself. With cloudflare,
    | replace --cutover, balancer, instance --dns and blacksmith:dns
    | move a site's domain and aliases to the right server. The token
    | needs Zone:Read and DNS:Edit. A site can opt out with "dns": false
    | in its site.json.
    |
    */
    'dns' => [
        // null or cloudflare
        'provider' => env('BLACKSMITH_DNS_PROVIDER'),

        'cloudflare' => [
            'token' => env('BLACKSMITH_CLOUDFLARE_TOKEN'),
            // Only used for records Blacksmith creates, existing records keep their setting
            'proxied' => env('BLACKSMITH_CLOUDFLARE_PROXIED', true),
            // Optional domain => zone ID, skips looking the zone up
            'zones' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Used when a blueprint, worker or pool leaves a value out. New server
    | profiles and site blueprints start from these, values already saved
    | in .blacksmith are kept as they are.
    |
    */
    'defaults' => [
        // The Linux user Forge runs sites, workers and the scheduler as.
        'user' => 'forge',

        // .blacksmith/servers/{profile}.json
        'server' => [
            'ubuntu_version' => '26.04',
            'php_version' => 'php85',
            'php_cli_version' => 'php85',
            'database_type' => null,
            'max_upload_size' => 5,
            'max_execution_time' => 30,
            'opcache' => true,
        ],

        // .blacksmith/sites/{domain}/site.json
        'site' => [
            'directory' => '/public',
            // laravel, symfony, statamic, wordpress, php, nextjs, nuxtjs, static-html, other or custom
            'project_type' => 'laravel',
            'php_version' => 'php85',
            'lets_encrypt' => true,
            'quick_deploy' => false,
            // Forge zero-downtime deployments (releases with a current symlink). Forge can only
            // set this when a site is created. null follows the deploy script: a script using
            // $CREATE_RELEASE() / $ACTIVATE_RELEASE() means true.
            'zero_downtime' => null,
            'scheduler' => false,
        ],

        // A site's "repository" in site.json
        'repository' => [
            // github, gitlab, bitbucket, gitlab-custom or custom
            'provider' => 'github',
            'branch' => 'main',
            'composer' => true,
        ],

        // Each entry in a site's "workers"
        'worker' => [
            'connection' => 'database',
            'queue' => 'default',
            'timeout' => 0,
            'sleep' => 10,
            'tries' => null,
            'processes' => 1,
            'stopwaitsecs' => 10,
            'force' => false,
            'php_version' => 'php85',
        ],

        'scheduler' => [
            // minutely, hourly, nightly, weekly or monthly
            'frequency' => 'minutely',
        ],

        // Load balanced pools and their nodes
        'balancer' => [
            // round_robin, least_conn or ip_hash
            'method' => 'round_robin',
            'weight' => 1,
            'port' => 80,
            'backup' => false,
        ],

        // Domains added for a site's aliases
        'domain' => [
            // from-www, to-www or none
            'www_redirect_type' => 'none',
            'allow_wildcard_subdomains' => false,
        ],

        // Let's Encrypt certificates
        'certificate' => [
            // http-01 or dns-01
            'verification_method' => 'http-01',
        ],
    ],
];
