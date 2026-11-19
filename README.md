![Grafite Blacksmith](GrafiteBlacksmith-banner.png)

**Blacksmith** - An easy way to handle rebuilding a Forge based application.

[![Build Status](https://github.com/GrafiteInc/Blacksmith/workflows/PHP%20Package%20Tests/badge.svg?branch=main)](https://github.com/GrafiteInc/Blacksmith/actions?query=workflow%3A%22PHP+Package+Tests%22)
[![Maintainability](https://api.codeclimate.com/v1/badges/e64a5c2c2392e34bb1a0/maintainability)](https://codeclimate.com/github/GrafiteInc/Blacksmith/maintainability)
[![Packagist](https://img.shields.io/packagist/dt/grafite/blacksmith.svg)](https://packagist.org/packages/grafite/blacksmith)
[![license](https://img.shields.io/github/license/mashape/apistatus.svg)](https://packagist.org/packages/grafite/blacksmith)

The Backsmith package is a easy to use tool for handling long term projects on Laravel Forge.

##### Author(s):
* [Matt Lantz](https://github.com/mlantz) ([@mattylantz](http://twitter.com/mattylantz), mattlantz at gmail dot com)

## Requirements

1. PHP 8.2+

## Compatibility and Support

| Laravel Version | Package Tag | Supported |
|-----------------|-------------|-----------|
| ^12.x-^13.x | 2.x (Forge API v2) | yes |
| ^11.x-^12.x | 1.x | no |

### Installation

Start a new Laravel project:
```php
composer create-project laravel/laravel your-project-name
```

Then run the following to add Support
```php
composer require "grafite/blacksmith"
```

Time to publish those assets!
```php
php artisan vendor:publish --provider="Grafite\Blacksmith\BlacksmithProvider"
```

Then set these in your `.env`:

```
BLACKSMITH_FORGE_TOKEN=
BLACKSMITH_FORGE_ORGANIZATION=      # optional when the token only has one organization
BLACKSMITH_SERVER_PROVIDER_TOKEN=   # DigitalOcean, for creating servers
BLACKSMITH_SSH_KEY=~/.ssh/id_ed25519 # must be one of your DigitalOcean SSH keys
```

### Forge API token

Blacksmith uses the Forge API v2, where everything belongs to an organization. Create a token at [forge.laravel.com/profile/api](https://forge.laravel.com/profile/api) with these scopes:

| Scope | Used for |
|-------|----------|
| `organization:view` | Finding your organization when `BLACKSMITH_FORGE_ORGANIZATION` is empty |
| `server:view`, `server:create`, `server:delete` | Reading, creating and deleting servers |
| `server:manage-php` | PHP versions, OPcache, upload size and execution time |
| `server:create-daemons`, `server:delete-daemons` | Queue workers (background processes) |
| `server:create-schedulers`, `server:delete-schedulers` | The scheduler |
| `site:create`, `site:delete`, `site:meta` | Sites, aliases and domains |
| `site:manage-deploys`, `site:manage-environment` | Deploy script, deployments, quick deploy and `.env` |
| `site:manage-ssl`, `site:manage-redirects`, `site:manage-security` | Certificates, redirects and security rules |
| `site:manage-project` | Load balancing |

`BLACKSMITH_FORGE_ORGANIZATION` is the slug in your Forge URLs. If the token can reach several organizations, Blacksmith asks you to set it.

### Defaults

Every default Blacksmith uses lives under `defaults` in `config/blacksmith.php`. That includes server profiles (Ubuntu, PHP, upload limits, OPcache), site blueprints (site type, web directory, PHP), repositories, queue workers, the scheduler, load balancing, alias domains, certificates and the Forge user. New profiles and blueprints start from these values. What's already saved in `.blacksmith` is kept. Keys missing from a published config fall back to the package's values, so new defaults reach you on upgrade.

## How it works

Blacksmith works like a small Terraform for Forge. `localize` copies a Forge server's configuration into **blueprints**: plain files you can read, edit and back up. Then the other commands push those blueprints back to Forge, to the same server or to brand new ones.

```
.blacksmith/
  servers/app.json              # server profile: type, PHP, Ubuntu, upload limits, provider size/region
  sites/example.com/site.json   # domain, aliases, repository, SSL, scheduler, workers, redirects, security
  sites/example.com/.env        # the site's env
  sites/example.com/.env.staging  # optional overrides for the "staging" instance only
  sites/example.com/deploy.sh   # deployment script
  state.json                    # which Forge server/site IDs each instance maps to
```

An **instance** is a named server, such as `prod`, `staging` or `example-com-node-1`, together with the sites on it. Blueprints never contain Forge IDs, so the same blueprint can be pushed to as many instances as you like.

## Commands

| Command | What it does |
|---|---|
| `blacksmith:localize` | Pull a Forge server (and its sites, workers, env, deploy script) into blueprints. Run it again any time to refresh. |
| `blacksmith:make-server {instance}` | Create a DigitalOcean droplet (or use `--ip`), connect it to Forge as a custom server, run the provision script over SSH and wait until it's ready. |
| `blacksmith:update-server {instance?}` | Push the blueprints to Forge. A site that's missing from the server gets created. Use `--dry-run` to see the changes first, `--only=env,workers` to limit what's pushed, and `--deploy` to deploy afterwards. |
| `blacksmith:workers {up\|down\|restart\|status}` | Manage queue workers. `up` makes them match the blueprint and never duplicates them. |
| `blacksmith:backup` | Zip `.blacksmith` and upload it to your backup storage. |
| `blacksmith:instance {name}` | Create a new server with copies of your sites and deploy them, in one step. |
| `blacksmith:balancer {name} --site=` | Put a load balancer in front of a site. |
| `blacksmith:scale {site} --to=N` | Add or remove web nodes behind the site's load balancer. `--remove=node` drains a node and then deletes it. |
| `blacksmith:replace {instance}` | Move an instance to a fresh server, for example for a new Ubuntu version. Then `--cutover` to swap it in, `--finish` to delete the old server, or `--abort` to throw the new one away. |

### Copy a site to a new server

```
php artisan blacksmith:localize --server=123456
php artisan blacksmith:instance staging --site=example.com
```

To change the env for that instance only (for example `APP_URL`), put the keys in `.blacksmith/sites/example.com/.env.staging` and run `blacksmith:update-server staging --only=env`.

### Load balance a site

```
php artisan blacksmith:balancer lb --site=example.com
# point example.com's DNS at the balancer, then
php artisan blacksmith:update-server lb --only=ssl
php artisan blacksmith:scale example.com --to=3
```

The server already running the site becomes the **primary** node. It is the only node that runs the scheduler and queue workers. New nodes are web-only: they get the same env and deploy script, but no scheduler, workers or certificate, because the balancer terminates SSL. Before scaling, make sure sessions, cache, the database and uploads are shared between servers (`scale` warns you about `file`/`sqlite`/`localhost` settings).

### Replace a server (OS upgrades)

Forge recommends against upgrading Ubuntu in place. Instead, build a new server, move your sites over and retire the old one. `blacksmith:replace` does that in two steps, with a pause in between for you to switch DNS.

First, set the new `ubuntu_version` **and** the matching `provider.image` in `.blacksmith/servers/app.json` (or pass `--profile=` to use another profile).

```
php artisan blacksmith:replace prod              # builds prod-next and deploys the sites, with no scheduler, workers or SSL yet
# test it (e.g. add "{new ip} example.com" to /etc/hosts), move any local data, then point DNS at the new IP
php artisan blacksmith:replace prod --cutover    # stops the scheduler and workers on the old server and turns them on, with SSL, on the new one
php artisan blacksmith:replace prod --finish     # deletes the old server (kept as prod-old) once you're happy
```

At cutover the new server takes over the name `prod`, so `.env.prod` overrides, pools and every other command keep working. If the instance sits behind a load balancer, DNS doesn't change: cutover points the balancer at the new server. Run `--abort` before cutover to delete the new server and start over.

Blacksmith copies what's in the blueprints. It does **not** copy databases, files in `storage/`, background processes other than queue workers, firewall rules, server SSH keys or cron jobs other than the scheduler, so move those yourself before cutover. `replace` warns you when the env points at a local database or disk.

### Queue workers

Forge API v2 has no queue worker endpoints, so each worker in `site.json` runs as a Forge **background process** in the site's directory:

```
php8.4 artisan queue:work redis --queue=default --sleep=10 --timeout=60 --tries=3
```

A site's workers are the background processes in its directory running `artisan queue:work`. Other background processes, like Horizon, are left alone. Workers are compared by command and process count, so changing a worker's `stopwaitsecs` alone does not recreate it.

### Upgrading from the Forge API v1

Create a new token with the scopes above and set `BLACKSMITH_FORGE_ORGANIZATION` if you have more than one organization. Then run `php artisan blacksmith:localize` to refresh the blueprints and `php artisan blacksmith:update-server --dry-run` to check nothing unexpected changes. Queue workers created through the v1 API are not visible to v2. If `workers status` shows none, run `workers up` and remove the old ones in Forge. The `daemon` worker option is gone, since `queue:work` always runs as a daemon.

### Upgrading from 1.x

Run `php artisan blacksmith:localize`. It converts the old `.blacksmith/{server-id}/config.json` folders into blueprints and state, then moves the originals to `.blacksmith/legacy`. Forge doesn't return security rule passwords, so check the `security` entries in each `site.json` after upgrading.

## Documentation

[https://documentation.grafite.ca/docs/utilities-blacksmith](https://documentation.grafite.ca/docs/utilities-blacksmith)

## License
Support is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)

### Bug Reporting and Feature Requests
Please add as many details as possible regarding submission of issues and feature requests

### Disclaimer
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
